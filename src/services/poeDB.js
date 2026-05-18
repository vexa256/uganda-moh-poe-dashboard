/**
 * poeDB.js — POE Offline-First Centralized IndexedDB Data Layer
 *
 * Place at:  src/services/poeDB.js
 * Import:    import { dbPut, dbGet, dbGetAll, safeDbPut, STORE, SYNC, APP } from '@/services/poeDB'
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  SINGLE SOURCE OF TRUTH for all IndexedDB operations in the POE app.   ║
 * ║                                                                         ║
 * ║  RULES (non-negotiable):                                                ║
 * ║    1. Every view imports from here — NEVER calls indexedDB.open()       ║
 * ║    2. ALL stores declared ONCE in applySchema() — never per-view        ║
 * ║    3. Version determined DYNAMICALLY — never hardcode a version number  ║
 * ║    4. window.__POE_CENTRAL_DB__ is the singleton — one connection app-wide║
 * ║    5. Child table records carry a generated client_uuid IDB key         ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  TO ADD A STORE: add to REQUIRED_STORES + applySchema() + STORE const  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */

// ─────────────────────────────────────────────────────────────────────────
// APP-WIDE CONSTANTS — single source, import these everywhere
// ─────────────────────────────────────────────────────────────────────────

/**
 * App-level constants shared across all views and sync engines.
 * Update these here — nowhere else.
 */
export const APP = Object.freeze({
    // App identity
    VERSION: '0.0.1',
    REFERENCE_DATA_VER: 'rda-2026-02-01',
    COUNTRY_CODE: 'UG',

    // IndexedDB — same name as original so no existing data is lost.
    // Cross-app isolation is handled by poe_code scoping in every IDB read.
    DB_NAME: 'poe_offline_db',
    MIN_SCHEMA_VERSION: 16,

    // Sync engine timing — import these, never hardcode per-view
    SYNC_RETRY_MS: 10000,  // flat retry interval for UNSYNCED records
    SYNC_TIMEOUT_MS: 8000,  // AbortController hard timeout per fetch

    // localStorage keys — country-prefixed for the same isolation reason.
    DEVICE_ID_KEY: 'ug_poe_device_id',
    DAY_COUNT_DAY_KEY: 'ug_poe_ps_day',  // session date tracker
    DAY_COUNT_CNT_KEY: 'ug_poe_ps_cnt',  // session screened count
})

// ─────────────────────────────────────────────────────────────────────────
// SYNC STATUS — matches MySQL ENUMs in poe_2026.sql exactly
// ─────────────────────────────────────────────────────────────────────────

export const SYNC = Object.freeze({
    UNSYNCED: 'UNSYNCED',  // saved locally, not yet attempted
    SYNCED: 'SYNCED',    // server confirmed, server_id returned
    FAILED: 'FAILED',    // server rejected (4xx non-retryable) — show human note
    // UI label map — NEVER show raw status values
    LABELS: {
        UNSYNCED: 'Pending',
        SYNCED: 'Uploaded',
        FAILED: 'Queued',
    },
})

// ─────────────────────────────────────────────────────────────────────────
// STORE NAMES — canonical constants, never hard-code strings in views
// ─────────────────────────────────────────────────────────────────────────

export const STORE = Object.freeze({
    USERS_LOCAL: 'users_local',
    PRIMARY_SCREENINGS: 'primary_screenings',
    NOTIFICATIONS: 'notifications',
    SECONDARY_SCREENINGS: 'secondary_screenings',
    SECONDARY_SYMPTOMS: 'secondary_symptoms',
    SECONDARY_EXPOSURES: 'secondary_exposures',
    SECONDARY_ACTIONS: 'secondary_actions',
    SECONDARY_SAMPLES: 'secondary_samples',
    SECONDARY_TRAVEL_COUNTRIES: 'secondary_travel_countries',
    SECONDARY_SUSPECTED_DISEASES: 'secondary_suspected_diseases',
    ALERTS: 'alerts',
    ALERT_FOLLOWUPS: 'alert_followups',
    AGGREGATED_SUBMISSIONS: 'aggregated_submissions',
    AGGREGATED_TEMPLATES_CACHE: 'aggregated_templates_cache',
    POE_NOTIFICATION_CONTACTS: 'poe_notification_contacts',
    SYNC_BATCHES: 'sync_batches',
    SYNC_BATCH_ITEMS: 'sync_batch_items',
})

/**
 * keyPath for each store.
 * Used by safeDbPut to read the correct primary key regardless of store.
 *
 * CHILD TABLE NOTE:
 *   The SQL schema for secondary child tables (symptoms, exposures, etc.)
 *   does NOT have a client_uuid column — they are server-only tables with
 *   a bigint PK. In IndexedDB we generate a client_uuid when creating each
 *   local record so IDB has a stable key for cursor/delete operations.
 *   When syncing, the child records are sent as arrays and the server
 *   handles its own IDs. The client_uuid is IDB-only.
 *
 * sync_batches uses client_batch_uuid (matches the SQL column name exactly).
 * sync_batch_items uses entity_client_uuid (matches the SQL column name exactly).
 */
export const STORE_KEY = Object.freeze({
    [STORE.USERS_LOCAL]: 'client_uuid',
    [STORE.PRIMARY_SCREENINGS]: 'client_uuid',
    [STORE.NOTIFICATIONS]: 'client_uuid',
    [STORE.SECONDARY_SCREENINGS]: 'client_uuid',
    [STORE.SECONDARY_SYMPTOMS]: 'client_uuid',       // IDB-only generated key
    [STORE.SECONDARY_EXPOSURES]: 'client_uuid',       // IDB-only generated key
    [STORE.SECONDARY_ACTIONS]: 'client_uuid',       // IDB-only generated key
    [STORE.SECONDARY_SAMPLES]: 'client_uuid',       // IDB-only generated key
    [STORE.SECONDARY_TRAVEL_COUNTRIES]: 'client_uuid',       // IDB-only generated key
    [STORE.SECONDARY_SUSPECTED_DISEASES]: 'client_uuid',       // IDB-only generated key
    [STORE.ALERTS]: 'client_uuid',
    [STORE.ALERT_FOLLOWUPS]: 'client_uuid',
    [STORE.AGGREGATED_SUBMISSIONS]: 'client_uuid',
    [STORE.AGGREGATED_TEMPLATES_CACHE]: 'id', // server id = cache key
    [STORE.POE_NOTIFICATION_CONTACTS]: 'client_uuid',
    [STORE.SYNC_BATCHES]: 'client_batch_uuid', // matches SQL column
    [STORE.SYNC_BATCH_ITEMS]: 'entity_client_uuid',// matches SQL column
})

/**
 * Required stores list — used in Phase 1 to detect which stores are missing.
 * Must stay in sync with STORE constant and applySchema().
 */
const REQUIRED_STORES = Object.values(STORE)


// ─────────────────────────────────────────────────────────────────────────
// PHASE 1 — PROBE (discover existing DB version without downgrading)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Open the DB without specifying a version — browser returns whatever it holds.
 * Reads version and store list, then EXPLICITLY closes before resolving.
 *
 * CRITICAL: In the new-DB case (onupgradeneeded fires), we close the DB
 * immediately to release the v1 connection. Without this, openAtVersion()
 * gets an onblocked event because the probe connection at v1 is still open
 * when we request a higher version — causing a deadlock on first install.
 *
 * @returns {Promise<{ version: number, storeNames: string[] }>}
 */
function probeDB() {
    return new Promise(resolve => {
        try {
            const req = indexedDB.open(APP.DB_NAME)

            // Existing DB: read version + stores, close, resolve
            req.onsuccess = () => {
                const db = req.result
                const version = db.version
                const storeNames = Array.from(db.objectStoreNames)
                db.close()                                    // ← explicit close
                resolve({ version, storeNames })
            }

            // Brand-new DB: close immediately to prevent blocking openAtVersion()
            req.onupgradeneeded = evt => {
                evt.target.result.close()                     // ← close the new v1 connection
                resolve({ version: evt.oldVersion || 0, storeNames: [] })
            }

            req.onerror = () => resolve({ version: 0, storeNames: [] })
        } catch {
            resolve({ version: 0, storeNames: [] })
        }
    })
}


// ─────────────────────────────────────────────────────────────────────────
// SCHEMA — SINGLE DECLARATION FOR ALL STORES AND INDEXES
// ─────────────────────────────────────────────────────────────────────────

/**
 * Declare every store and index idempotently.
 * Called ONLY inside onupgradeneeded — never directly.
 *
 * ensureStore / ensureIdx guards: create only if absent, never touch existing.
 * Safe to run at any version transition, at any time.
 *
 * INDEX RATIONALE (chosen for actual query patterns in existing and future views):
 *
 *   users_local:
 *     sync_status            — sync queue badge count + pending filter
 *     role_key               — RBAC filtering in user list
 *     country_code           — geographic scope filter
 *     is_active              — active/inactive filter
 *     username / username_ci — dedup check (case-insensitive)
 *     email_ci               — dedup check
 *
 *   primary_screenings:
 *     sync_status            — sync queue
 *     poe_code               — load today's records for session bar
 *     captured_at            — date range queries for records view
 *     captured_by_user_id    — filter by officer
 *     referral_created       — cancel-referral revert: find records with flag=1
 *     symptoms_present       — aggregated stats (symptomatic count)
 *
 *   notifications:
 *     sync_status            — sync queue
 *     poe_code               — referral queue scoped to POE
 *     primary_screening_id   — lookup notification from primary record
 *     status                 — filter OPEN / IN_PROGRESS / CLOSED
 *     notification_type      — filter SECONDARY_REFERRAL vs ALERT
 *     priority               — sort queue by CRITICAL/HIGH/NORMAL
 *
 *   secondary_screenings:
 *     sync_status            — sync queue
 *     poe_code               — geographic scope
 *     case_status            — filter OPEN / IN_PROGRESS / DISPOSITIONED / CLOSED
 *     primary_screening_id   — link from primary record
 *     notification_id        — idempotency check in openCase()
 *     opened_by_user_id      — filter by officer
 *
 *   secondary child tables (symptoms/exposures/actions/samples/travel/diseases):
 *     secondary_screening_id — fetch all children for a case (replace-all pattern)
 *
 *   alerts:
 *     sync_status            — sync queue
 *     poe_code               — geographic scope
 *     status                 — OPEN / ACKNOWLEDGED / CLOSED filter
 *     risk_level             — HIGH / CRITICAL filter
 *     secondary_screening_id — link from case (alert detail view)
 *
 *   aggregated_submissions:
 *     sync_status            — sync queue
 *     poe_code               — POE scope
 *     period_start           — date range filter
 *     district_code          — district-level aggregation view
 *
 *   sync_batches:
 *     status                 — filter by COMPLETED / FAILED
 *     device_id              — filter by device
 *
 *   sync_batch_items:
 *     sync_batch_id          — get all items for a batch
 *     entity_type            — filter by PRIMARY / NOTIFICATION / SECONDARY
 */
function applySchema(db, tx) {
    function ensureStore(name, keyPath) {
        return db.objectStoreNames.contains(name)
            ? tx.objectStore(name)
            : db.createObjectStore(name, { keyPath })
    }
    function ensureIdx(s, name, keyPath) {
        if (!s.indexNames.contains(name)) s.createIndex(name, keyPath)
    }

    // ── users_local ──────────────────────────────────────────────────────
    {
        const s = ensureStore('users_local', 'client_uuid')
        ;['sync_status', 'role_key', 'country_code', 'is_active',
            'username', 'username_ci', 'email_ci'].forEach(f => ensureIdx(s, f, f))
    }

    // ── primary_screenings ───────────────────────────────────────────────
    {
        const s = ensureStore('primary_screenings', 'client_uuid')
        ;['sync_status', 'poe_code', 'captured_at', 'captured_by_user_id',
            'referral_created', 'symptoms_present'].forEach(f => ensureIdx(s, f, f))
    }

    // ── notifications ─────────────────────────────────────────────────────
    {
        const s = ensureStore('notifications', 'client_uuid')
        ;['sync_status', 'poe_code', 'primary_screening_id', 'status',
            'notification_type', 'priority'].forEach(f => ensureIdx(s, f, f))
    }

    // ── secondary_screenings ─────────────────────────────────────────────
    {
        const s = ensureStore('secondary_screenings', 'client_uuid')
        ;['sync_status', 'poe_code', 'case_status',
            'primary_screening_id', 'notification_id', 'opened_by_user_id'].forEach(f => ensureIdx(s, f, f))
    }

    // ── secondary child tables ────────────────────────────────────────────
    // keyPath = client_uuid (IDB-only generated UUID — not a SQL column)
    // Each record must include client_uuid when created locally.
    ['secondary_symptoms', 'secondary_exposures', 'secondary_actions',
        'secondary_samples', 'secondary_travel_countries', 'secondary_suspected_diseases',
    ].forEach(name => {
        const s = ensureStore(name, 'client_uuid')
        ensureIdx(s, 'secondary_screening_id', 'secondary_screening_id')
    })

    // ── alerts ────────────────────────────────────────────────────────────
    {
        const s = ensureStore('alerts', 'client_uuid')
        ;['sync_status', 'poe_code', 'status', 'risk_level',
            'secondary_screening_id'].forEach(f => ensureIdx(s, f, f))
    }

    // ── alert_followups ───────────────────────────────────────────────────
    // RTSL 14 early response actions per alert — enforces 7-1-7 follow-up
    {
        const s = ensureStore('alert_followups', 'client_uuid')
        ;['sync_status', 'poe_code', 'alert_id', 'alert_client_uuid',
            'status', 'due_at', 'action_code'].forEach(f => ensureIdx(s, f, f))
    }

    // ── aggregated_submissions ────────────────────────────────────────────
    {
        const s = ensureStore('aggregated_submissions', 'client_uuid')
        ;['sync_status', 'poe_code', 'period_start', 'district_code', 'template_id'].forEach(f => ensureIdx(s, f, f))
    }

    // ── aggregated_templates_cache ────────────────────────────────────────
    // Offline copy of PUBLISHED templates so the Hub + submission form work
    // with zero network. Key = server template id. Polled/refreshed by
    // AggregatedHub.vue on every view enter + 30s poll.
    {
        const s = ensureStore('aggregated_templates_cache', 'id')
        ;['country_code', 'status', 'reporting_frequency', 'is_default'].forEach(f => ensureIdx(s, f, f))
    }

    // ── poe_notification_contacts ────────────────────────────────────────
    // Offline landing store for admin-authored POE contacts. Normally the
    // admin UI writes direct to the server, but if an admin drafts a
    // contact offline it lands here and is picked up by SyncManagement.
    {
        const s = ensureStore('poe_notification_contacts', 'client_uuid')
        ;['sync_status', 'poe_code', 'country_code', 'district_code', 'level'].forEach(f => ensureIdx(s, f, f))
    }

    // ── sync_batches (keyPath = client_batch_uuid matches SQL column) ─────
    {
        const s = ensureStore('sync_batches', 'client_batch_uuid')
        ;['status', 'device_id'].forEach(f => ensureIdx(s, f, f))
    }

    // ── sync_batch_items (keyPath = entity_client_uuid matches SQL column) ─
    {
        const s = ensureStore('sync_batch_items', 'entity_client_uuid')
        ;['sync_batch_id', 'entity_type'].forEach(f => ensureIdx(s, f, f))
    }
}


// ─────────────────────────────────────────────────────────────────────────
// PHASE 2 — OPEN AT CORRECT VERSION
// ─────────────────────────────────────────────────────────────────────────

function openAtVersion(version) {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(APP.DB_NAME, version)

        req.onupgradeneeded = evt => {
            applySchema(evt.target.result, evt.target.transaction)
        }

        req.onsuccess = () => {
            const db = req.result

            // Another tab opened a higher version → gracefully close and clear singleton
            // so the next getPoeDB() call re-probes and upgrades
            db.onversionchange = () => {
                db.close()
                window.__POE_CENTRAL_DB__ = null
                _log('INFO', `DB versionchange → closed v${db.version}, singleton cleared`)
            }

            db.onclose = () => {
                window.__POE_CENTRAL_DB__ = null
                _log('INFO', 'DB connection closed — singleton cleared')
            }

            _log('INFO', `DB ready v${db.version} (${db.objectStoreNames.length} stores)`)
            resolve(db)
        }

        req.onerror = () => {
            window.__POE_CENTRAL_DB__ = null
            reject(req.error)
        }

        req.onblocked = () => {
            // Another tab holds an older version — warn but do not reject.
            // The user must close other tabs. The request stays pending until unblocked.
            _log('WARN',
                'DB upgrade BLOCKED — close all other tabs running this app, then reload. ' +
                `Waiting to open v${version}…`)
        }
    })
}


// ─────────────────────────────────────────────────────────────────────────
// SINGLETON — getPoeDB()
// ─────────────────────────────────────────────────────────────────────────

/**
 * Get (or initialize) the shared IDBDatabase connection.
 * Cached in window.__POE_CENTRAL_DB__ as a Promise<IDBDatabase>.
 * All callers await the same promise — the DB is opened exactly once per session.
 *
 * The two-phase strategy:
 *   Phase 1 — probe: read current version + store list, close immediately
 *   Phase 2 — open:  if any store missing, open at probedVersion+1 (upgrade)
 *                    if all stores present, open at max(probed, MIN_SCHEMA_VERSION)
 *
 * This eliminates VersionError permanently — we never request a version
 * lower than what the browser already holds.
 *
 * @returns {Promise<IDBDatabase>}
 */
export function getPoeDB() {
    if (window.__POE_CENTRAL_DB__) return window.__POE_CENTRAL_DB__

    window.__POE_CENTRAL_DB__ = (async () => {
        try {
            const { version: probed, storeNames } = await probeDB()

            const missing = REQUIRED_STORES.filter(s => !storeNames.includes(s))
            const needsUpgrade = missing.length > 0

            const target = needsUpgrade
                ? Math.max(probed, APP.MIN_SCHEMA_VERSION - 1) + 1
                : Math.max(probed, APP.MIN_SCHEMA_VERSION)

            if (needsUpgrade) {
                _log('INFO', `Upgrade needed [${missing.join(', ')}] → opening v${target} (probed v${probed})`)
            } else {
                _log('INFO', `All stores present → opening v${target} (probed v${probed})`)
            }

            return await openAtVersion(target)
        } catch (e) {
            window.__POE_CENTRAL_DB__ = null
            throw e
        }
    })()

    return window.__POE_CENTRAL_DB__
}


// ─────────────────────────────────────────────────────────────────────────
// PRIMITIVE OPERATIONS
// ─────────────────────────────────────────────────────────────────────────

/**
 * Write (insert or fully replace) a record.
 * For updates to existing records, use safeDbPut() to guard against stale writes.
 *
 * @param {string} store
 * @param {object} record  Must include the store's keyPath field (see STORE_KEY)
 * @returns {Promise<void>}
 */
export async function dbPut(store, record) {
    const db = await getPoeDB()
    // Sanitise the record: IndexedDB uses structured cloning, which throws
    // DataCloneError on non-cloneable values (Vue reactive proxies,
    // functions, class instances, DOM nodes…).  A JSON roundtrip strips
    // proxies + non-serialisable fields and is safe for the plain-data
    // records every callsite stores.  Preferred over `structuredClone`
    // here because some Vue proxies still fail it on older browsers.
    const safe = toPlainRecord(record)
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readwrite')
        tx.objectStore(store).put(safe)
        tx.oncomplete = () => resolve()
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error ?? new Error(`IDB put aborted on ${store}`))
    })
}

/**
 * Deep-clone to a plain JS object tree — strips Vue reactive proxies,
 * class instances, functions, Symbols, and any other non-structured-
 * cloneable value.  JSON roundtrip is sufficient for the records this
 * app stores (screenings, users, alerts, notifications, cache blobs).
 * Centralising this here means every dbPut caller is safe by default.
 */
function toPlainRecord(record) {
    if (record == null || typeof record !== 'object') return record
    try {
        return JSON.parse(JSON.stringify(record))
    } catch (e) {
        // Fallback: shallow copy of own enumerable properties; drops funcs.
        const out = {}
        for (const k of Object.keys(record)) {
            const v = record[k]
            if (typeof v === 'function' || typeof v === 'symbol') continue
            out[k] = v
        }
        return out
    }
}

/**
 * Read a single record by primary key.
 *
 * @param {string} store
 * @param {string} key  Value of the store's keyPath field
 * @returns {Promise<object|null>}
 */
export async function dbGet(store, key) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db.transaction(store, 'readonly').objectStore(store).get(key)
        r.onsuccess = () => resolve(r.result ?? null)
        r.onerror = () => reject(r.error)
    })
}

/**
 * Read ALL records from a store.
 * Avoid on large stores — use dbGetByIndex() for scoped reads.
 *
 * @param {string} store
 * @returns {Promise<object[]>}
 */
export async function dbGetAll(store) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db.transaction(store, 'readonly').objectStore(store).getAll()
        r.onsuccess = () => resolve(r.result ?? [])
        r.onerror = () => reject(r.error)
    })
}

/**
 * Read all records matching an index value (exact match).
 *
 * @param {string} store
 * @param {string} indexName  Must be declared in applySchema()
 * @param {*}      value
 * @returns {Promise<object[]>}
 */
export async function dbGetByIndex(store, indexName, value) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db
            .transaction(store, 'readonly')
            .objectStore(store)
            .index(indexName)
            .getAll(IDBKeyRange.only(value))
        r.onsuccess = () => resolve(r.result ?? [])
        r.onerror = () => reject(r.error)
    })
}

/**
 * Count records matching an index value.
 * More efficient than dbGetByIndex when only the count is needed (sync badges).
 *
 * @param {string} store
 * @param {string} indexName
 * @param {*}      value
 * @returns {Promise<number>}
 */
export async function dbCountIndex(store, indexName, value) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db
            .transaction(store, 'readonly')
            .objectStore(store)
            .index(indexName)
            .count(IDBKeyRange.only(value))
        r.onsuccess = () => resolve(r.result)
        r.onerror = () => reject(r.error)
    })
}

/**
 * Read records within an IDBKeyRange on an index.
 * Needed for date-range queries (e.g. screenings between period_start and period_end)
 * and for aggregated submissions period filtering.
 *
 * @param {string}      store
 * @param {string}      indexName
 * @param {IDBKeyRange} range     Use IDBKeyRange.bound(), .lowerBound(), .upperBound()
 * @returns {Promise<object[]>}
 *
 * @example
 * // All primary screenings from today
 * const today = new Date().toISOString().slice(0, 10)
 * const recs  = await dbGetRange(STORE.PRIMARY_SCREENINGS, 'captured_at',
 *   IDBKeyRange.bound(today + ' 00:00:00', today + ' 23:59:59'))
 */
export async function dbGetRange(store, indexName, range) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db
            .transaction(store, 'readonly')
            .objectStore(store)
            .index(indexName)
            .getAll(range)
        r.onsuccess = () => resolve(r.result ?? [])
        r.onerror = () => reject(r.error)
    })
}

/**
 * Delete a single record by primary key.
 *
 * @param {string} store
 * @param {string} key
 * @returns {Promise<void>}
 */
export async function dbDelete(store, key) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readwrite')
        tx.objectStore(store).delete(key)
        tx.oncomplete = () => resolve()
        tx.onerror = () => reject(tx.error)
    })
}

/**
 * Delete ALL records matching an index value in a single transaction.
 *
 * This is the correct primitive for the secondary screening replace-all pattern:
 *   await dbDeleteByIndex(STORE.SECONDARY_SYMPTOMS, 'secondary_screening_id', caseId)
 *   await dbPutBatch(STORE.SECONDARY_SYMPTOMS, freshSymptoms)
 *
 * Without this, the replace-all required N+1 transactions (one delete per record).
 * With this, it is 2 transactions total regardless of record count.
 *
 * @param {string} store
 * @param {string} indexName
 * @param {*}      value
 * @returns {Promise<number>}  Count of records deleted
 */
export async function dbDeleteByIndex(store, indexName, value) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readwrite')
        const obj = tx.objectStore(store).index(indexName)
        // Open a cursor over all matching records and delete each
        const req = obj.openCursor(IDBKeyRange.only(value))
        let count = 0
        req.onsuccess = evt => {
            const cursor = evt.target.result
            if (cursor) {
                cursor.delete()
                count++
                cursor.continue()
            }
        }
        tx.oncomplete = () => resolve(count)
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error ?? new Error(`dbDeleteByIndex aborted on ${store}`))
    })
}

/**
 * Write multiple records in a single readwrite transaction.
 * Dramatically faster than looping dbPut() — one transaction instead of N.
 * Use for array inserts (symptom inventory, exposure list, travel countries).
 *
 * @param {string}   store
 * @param {object[]} records  All must include the store's keyPath field
 * @returns {Promise<void>}
 */
export async function dbPutBatch(store, records) {
    if (!records || !records.length) return
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readwrite')
        const obj = tx.objectStore(store)
        for (const rec of records) obj.put(toPlainRecord(rec))
        tx.oncomplete = () => resolve()
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error ?? new Error(`dbPutBatch aborted on ${store}`))
    })
}

/**
 * Replace-all: delete all records for an index value, then insert a fresh array.
 * Single atomic operation — used for all secondary child table sync patterns.
 *
 * If newRecords is empty, all existing records for the index value are deleted
 * and nothing is inserted.
 *
 * @param {string}   store
 * @param {string}   indexName        e.g. 'secondary_screening_id'
 * @param {*}        indexValue       e.g. the case's client_uuid
 * @param {object[]} newRecords       The complete replacement array
 * @returns {Promise<{ deleted: number, inserted: number }>}
 *
 * @example
 * await dbReplaceAll(
 *   STORE.SECONDARY_SYMPTOMS,
 *   'secondary_screening_id',
 *   case.client_uuid,
 *   freshSymptomRecords   // must each have a client_uuid field
 * )
 */
export async function dbReplaceAll(store, indexName, indexValue, newRecords) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readwrite')
        const obj = tx.objectStore(store)
        let deleted = 0

        // Step 1: delete all existing records for this index value
        const req = obj.index(indexName).openCursor(IDBKeyRange.only(indexValue))
        req.onsuccess = evt => {
            const cursor = evt.target.result
            if (cursor) { cursor.delete(); deleted++; cursor.continue() }
            else {
                // Step 2: insert fresh records
                for (const rec of (newRecords || [])) obj.put(toPlainRecord(rec))
            }
        }

        tx.oncomplete = () => resolve({ deleted, inserted: (newRecords || []).length })
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error ?? new Error(`dbReplaceAll aborted on ${store}`))
    })
}

/**
 * Safe put — blocks a background sync from overwriting a local edit.
 *
 * Compares record_version: if the stored version is HIGHER than the incoming
 * write, the write is discarded (local edit wins). Otherwise proceeds normally.
 *
 * Uses STORE_KEY to correctly identify each store's primary key field —
 * handles sync_batches (client_batch_uuid) and sync_batch_items (entity_client_uuid)
 * transparently without the caller needing to know which field is the key.
 *
 * WHEN TO USE:  any update that happens asynchronously (sync callbacks, toggles)
 * WHEN NOT TO:  new record inserts (no existing version to compare) → use dbPut()
 *
 * @param {string} store
 * @param {object} incoming  Must include the store's keyPath field + record_version
 * @returns {Promise<void>}
 */
export async function safeDbPut(store, incoming) {
    const keyField = STORE_KEY[store] ?? 'client_uuid'
    const keyValue = incoming[keyField]
    const existing = await dbGet(store, keyValue).catch(() => null)

    if (existing && (existing.record_version ?? 0) > (incoming.record_version ?? 0)) {
        _log('WARN',
            `safeDbPut: stale write BLOCKED — ${store}/${keyValue} ` +
            `(stored v${existing.record_version} > write v${incoming.record_version})`)
        return
    }
    return dbPut(store, incoming)
}

/**
 * Check whether a record exists without loading it.
 * Uses count() which is cheaper than get() for existence checks.
 *
 * @param {string} store
 * @param {string} key
 * @returns {Promise<boolean>}
 */
export async function dbExists(store, key) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db.transaction(store, 'readonly').objectStore(store).count(IDBKeyRange.only(key))
        r.onsuccess = () => resolve(r.result > 0)
        r.onerror = () => reject(r.error)
    })
}

/**
 * Atomic multi-store write — execute multiple puts across different stores
 * in a single IDB transaction.
 *
 * Required when data consistency demands that two stores update together
 * or not at all (e.g. marking both primary_screening and its notification
 * as SYNCED after a successful batch sync).
 *
 * @param {Array<{ store: string, record: object }>} writes
 * @returns {Promise<void>}
 *
 * @example
 * await dbAtomicWrite([
 *   { store: STORE.PRIMARY_SCREENINGS, record: { ...screening, sync_status: SYNC.SYNCED } },
 *   { store: STORE.NOTIFICATIONS,      record: { ...notif,    sync_status: SYNC.SYNCED } },
 * ])
 */
export async function dbAtomicWrite(writes) {
    if (!writes || !writes.length) return
    const storeNames = [...new Set(writes.map(w => w.store))]
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeNames, 'readwrite')
        for (const { store, record } of writes) {
            tx.objectStore(store).put(toPlainRecord(record))
        }
        tx.oncomplete = () => resolve()
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error ?? new Error('dbAtomicWrite aborted'))
    })
}

/**
 * Get all records matching an index value, filtered by a predicate.
 * Use when you need both an index filter AND an in-memory condition.
 *
 * @param {string}   store
 * @param {string}   indexName
 * @param {*}        value
 * @param {Function} [predicate]  (record) => boolean
 * @returns {Promise<object[]>}
 *
 * @example
 * // All UNSYNCED primary screenings from today
 * const today = new Date().toISOString().slice(0, 10)
 * const recs  = await dbQuery(STORE.PRIMARY_SCREENINGS, 'sync_status', SYNC.UNSYNCED,
 *   r => r.captured_at?.startsWith(today))
 */
export async function dbQuery(store, indexName, value, predicate = null) {
    const all = await dbGetByIndex(store, indexName, value)
    return predicate ? all.filter(predicate) : all
}


// ─────────────────────────────────────────────────────────────────────────
// SHARED UTILITIES — import these in every view, never re-declare them
// ─────────────────────────────────────────────────────────────────────────

/**
 * Generate a UUID v4.
 * Uses crypto.randomUUID() when available (all modern browsers + Capacitor Android).
 * Falls back to Math.random() for very old WebViews.
 *
 * @returns {string}  e.g. "550e8400-e29b-41d4-a716-446655440000"
 */
export function genUUID() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16)
    })
}

/**
 * Current local datetime as a MySQL-compatible string.
 * Format: "YYYY-MM-DD HH:MM:SS"  (matches all datetime columns in poe_2026.sql)
 *
 * @returns {string}  e.g. "2026-03-24 14:40:20"
 */
export function isoNow() {
    return new Date().toISOString().replace('T', ' ').slice(0, 19)
}

/**
 * Detect the current runtime platform.
 * Matches platform ENUM('ANDROID','IOS','WEB') in poe_2026.sql.
 *
 * @returns {'ANDROID'|'IOS'|'WEB'}
 */
export function getPlatform() {
    const ua = navigator.userAgent
    if (/android/i.test(ua)) return 'ANDROID'
    if (/iP(ad|hone|od)/i.test(ua)) return 'IOS'
    return 'WEB'
}

/**
 * Get (or generate and persist) a stable device identifier.
 * Stored in localStorage under APP.DEVICE_ID_KEY.
 * Format: "ECSA-{BASE36_TIMESTAMP}-{RANDOM_HEX}"
 *
 * @returns {string}  e.g. "ECSA-LQ1X2Y3Z-A7B4C2"
 */
export function getDeviceId() {
    let id = localStorage.getItem(APP.DEVICE_ID_KEY)
    if (!id) {
        id = `ECSA-${Date.now().toString(36).toUpperCase()}-${Math.random().toString(36).slice(2, 8).toUpperCase()}`
        localStorage.setItem(APP.DEVICE_ID_KEY, id)
    }
    return id
}

/**
 * Build the mandatory base fields that every new offline record must include.
 *
 * Covers all fields from the AUTH_DATA quick reference stamp requirement:
 *   sync fields, device stamp, geographic scope, versioning, timestamps.
 *
 * USAGE: Spread this into your record object, then add domain-specific fields.
 * The `overrides` param lets you set domain fields in the same call.
 *
 * @param {object} auth      The AUTH_DATA object from sessionStorage
 * @param {object} [overrides]  Domain-specific fields that override base defaults
 * @returns {object}
 *
 * @example
 * const screening = createRecordBase(auth, {
 *   gender:           'MALE',
 *   symptoms_present: 0,
 *   captured_at:      isoNow(),
 * })
 * // screening now has ALL mandatory base fields + gender + symptoms_present + captured_at
 */
export function createRecordBase(auth, overrides = {}) {
    const now = isoNow()
    return {
        // Identity
        client_uuid: genUUID(),
        server_id: null,
        server_received_at: null,
        idempotency_key: null,
        reference_data_version: APP.REFERENCE_DATA_VER,

        // Geographic scope — from AUTH_DATA pre-flattened shortcuts
        country_code: auth?.country_code ?? null,
        province_code: auth?.province_code ?? null,
        pheoc_code: auth?.pheoc_code ?? null,
        district_code: auth?.district_code ?? null,
        poe_code: auth?.poe_code ?? null,

        // Creator identity
        created_by_user_id: auth?.id ?? null,
        created_by_role: auth?.role_key ?? null, // IDB audit field — not in SQL schema

        // Device
        device_id: getDeviceId(),
        app_version: APP.VERSION,
        platform: getPlatform(),

        // Sync state — always starts UNSYNCED
        sync_status: SYNC.UNSYNCED,
        synced_at: null,
        sync_attempt_count: 0,
        last_sync_error: null,
        sync_note: null,

        // Versioning — increment on every write so safeDbPut can detect stale writes
        record_version: 1,

        // Timestamps
        created_at: now,
        updated_at: now,

        // Caller overrides always win
        ...overrides,
    }
}


// ─────────────────────────────────────────────────────────────────────────
// TOTAL COUNT — count all records without loading them
// ─────────────────────────────────────────────────────────────────────────

/**
 * Count ALL records in a store.
 * Uses IDB's native count() — does NOT load any record data into memory.
 * Use for dashboard badges, existence checks on large stores.
 *
 * To count by a specific index value (e.g. only UNSYNCED), use dbCountIndex().
 *
 * @param {string} store
 * @returns {Promise<number>}
 *
 * @example
 * const total = await dbGetCount(STORE.PRIMARY_SCREENINGS)
 * // → 847  (no records loaded, just the count)
 */
export async function dbGetCount(store) {
    const db = await getPoeDB()
    return new Promise((resolve, reject) => {
        const r = db.transaction(store, 'readonly').objectStore(store).count()
        r.onsuccess = () => resolve(r.result)
        r.onerror = () => reject(r.error)
    })
}


// ─────────────────────────────────────────────────────────────────────────
// INTERNAL LOGGER
// ─────────────────────────────────────────────────────────────────────────

function _log(level, msg, data) {
    const styles = {
        INFO: 'color:#0066CC;font-weight:600',
        WARN: 'color:#E65100;font-weight:600',
        ERROR: 'color:#DC3545;font-weight:600',
    }
    const hdr = `%c[POE-DB][${level}] ${new Date().toISOString().slice(11, 23)} — ${msg}`
    if (data) { console.groupCollapsed(hdr, styles[level] ?? ''); console.log(data); console.groupEnd() }
    else console.log(hdr, styles[level] ?? '')
}


/*
 * ═══════════════════════════════════════════════════════════════════════════
 * EXPORTS SUMMARY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Constants:
 *    APP        { VERSION, REFERENCE_DATA_VER, DB_NAME, MIN_SCHEMA_VERSION,
 *                 SYNC_RETRY_MS, SYNC_TIMEOUT_MS, DEVICE_ID_KEY,
 *                 DAY_COUNT_DAY_KEY, DAY_COUNT_CNT_KEY }
 *    STORE      { USERS_LOCAL, PRIMARY_SCREENINGS, NOTIFICATIONS, … }
 *    STORE_KEY  { [store] → keyPath string }
 *    SYNC       { UNSYNCED, SYNCED, FAILED, LABELS }
 *
 *  Shared utilities (import instead of re-declaring per view):
 *    genUUID()                                    → uuid string
 *    isoNow()                                     → "YYYY-MM-DD HH:MM:SS"
 *    getPlatform()                                → 'ANDROID'|'IOS'|'WEB'
 *    getDeviceId()                                → stable localStorage device id
 *    createRecordBase(auth, overrides?)           → object with all mandatory fields
 *
 *  DB access:
 *    getPoeDB()                                   → IDBDatabase (singleton)
 *
 *  Single-record:
 *    dbPut(store, record)                         → void
 *    dbGet(store, key)                            → record | null
 *    dbDelete(store, key)                         → void
 *    dbExists(store, key)                         → boolean
 *    safeDbPut(store, record)                     → void  (version-guarded)
 *
 *  Multi-record reads:
 *    dbGetAll(store)                              → record[]
 *    dbGetByIndex(store, index, value)            → record[]
 *    dbGetRange(store, index, IDBKeyRange)        → record[]
 *    dbQuery(store, index, value, predicate?)     → record[]
 *    dbCountIndex(store, index, value)            → number
 *    dbGetCount(store)                            → number (total, no filter)
 *
 *  Multi-record writes:
 *    dbPutBatch(store, records)                   → void  (1 tx, N records)
 *    dbDeleteByIndex(store, index, value)         → number (deleted count)
 *    dbReplaceAll(store, index, value, records)   → { deleted, inserted }
 *    dbAtomicWrite([{ store, record }])           → void  (multi-store 1 tx)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * MIGRATION — updating existing views
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * import { getPoeDB, dbPut, dbGet, dbGetAll, safeDbPut,
 *          dbDelete, dbExists, dbGetByIndex, dbGetRange, dbGetCount,
 *          dbCountIndex, dbPutBatch, dbDeleteByIndex, dbReplaceAll,
 *          dbAtomicWrite, dbQuery,
 *          genUUID, isoNow, getPlatform, getDeviceId, createRecordBase,
 *          STORE, STORE_KEY, SYNC, APP } from '@/services/poeDB'
 *
 * ManageUsers.vue changes (11 call sites):
 *   dbAll()          → dbGetAll(STORE.USERS_LOCAL)
 *   dbGet(uuid)      → dbGet(STORE.USERS_LOCAL, uuid)
 *   dbPut(rec)       → dbPut(STORE.USERS_LOCAL, rec)
 *   safeDbPut(rec)   → safeDbPut(STORE.USERS_LOCAL, rec)
 *   raw db.delete()  → dbDelete(STORE.USERS_LOCAL, uuid)
 *
 * PrimaryScreeningView.vue: delete §1–§2 (probeDB through primitives), import above.
 *   Replace 'rda-2026-02-01' → APP.REFERENCE_DATA_VER
 *   Replace APP_VER          → APP.VERSION
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * HOW TO ADD A NEW STORE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. Add name to REQUIRED_STORES (auto-derived from STORE — just add to STORE)
 * 2. Add keyPath to STORE_KEY
 * 3. Add ensureStore + ensureIdx calls to applySchema()
 * 4. Done. Next app open detects missing store, increments version, upgrades.
 */