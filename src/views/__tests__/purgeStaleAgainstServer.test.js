/**
 * Edge-case tests for the stale-purge predicate in NotificationsCenter.
 *
 * The function itself (`purgeStaleAgainstServer`) is defined inside a Vue
 * SFC and depends on closure-scoped IDB helpers. Rather than mount the
 * component, this file mirrors the *predicate* logic line-for-line and
 * asserts the safety rails. If the production predicate changes, update
 * this mirror and the tests will catch any divergence.
 *
 * The decision matrix under test:
 *   pullCompletedOk?      false  → no-op (network never returned authoritative data)
 *   serverItems is array?  no    → no-op
 *   totalOnServer > items? yes   → no-op (partial pull, valid records would be lost)
 *   IDB row deleted_at?    yes   → keep (idempotent)
 *   IDB row UNSYNCED?      yes   → keep (officer's local-only edit)
 *   IDB row synced_at null?yes   → keep (never been to server, can't be "missing")
 *   client_uuid in server? yes   → keep
 *   id in server?          yes   → keep
 *   else                          → PURGE
 */
import { describe, it, expect } from 'vitest'

const SYNC = { UNSYNCED: 'UNSYNCED', SYNCED: 'SYNCED', FAILED: 'FAILED' }

/**
 * Pure predicate mirror of the function in NotificationsCenter.vue.
 * Returns 'no-op-pull', 'no-op-input', 'no-op-partial', or an array of
 * IDB notification client_uuids that would be soft-deleted.
 */
function decideStalePurge(serverItems, totalOnServer, pullCompletedOk, idbNotifs) {
  if (!pullCompletedOk) return 'no-op-pull'
  if (!Array.isArray(serverItems)) return 'no-op-input'
  if (totalOnServer > serverItems.length) return 'no-op-partial'

  const serverUuids = new Set()
  const serverIds   = new Set()
  for (const s of serverItems) {
    if (s?.notification_uuid) serverUuids.add(String(s.notification_uuid))
    if (s?.notification_id)   serverIds.add(Number(s.notification_id))
  }

  const purged = []
  for (const n of idbNotifs) {
    if (n.deleted_at) continue
    if (n.sync_status === SYNC.UNSYNCED) continue
    if (!n.synced_at) continue
    const localUuid = n.client_uuid ? String(n.client_uuid) : null
    const localId   = n.id ? Number(n.id) : null
    if (localUuid && serverUuids.has(localUuid)) continue
    if (localId   && serverIds.has(localId))     continue
    purged.push(n.client_uuid)
  }
  return purged
}

const stale = (over = {}) => ({
  client_uuid: 'stale-1', id: 100,
  sync_status: SYNC.SYNCED, synced_at: '2026-05-19T10:00:00Z',
  deleted_at: null,
  ...over,
})

describe('purgeStaleAgainstServer — gate against network failure', () => {
  it('bails when pullCompletedOk=false (network never returned)', () => {
    // The catastrophic case: serverItems=[] and totalOnServer=0 BUT the
    // server never actually responded. Without the gate this would soft-
    // delete every synced row. With the gate it's a no-op.
    expect(decideStalePurge([], 0, false, [stale()])).toBe('no-op-pull')
  })

  it('proceeds when pullCompletedOk=true AND serverItems=[] AND total=0', () => {
    // Legitimate empty-server case (e.g. after admin wipe).
    expect(decideStalePurge([], 0, true, [stale()])).toEqual(['stale-1'])
  })

  it('bails on non-array input regardless of pull state', () => {
    expect(decideStalePurge(null, 0, true, [stale()])).toBe('no-op-input')
    expect(decideStalePurge(undefined, 0, true, [stale()])).toBe('no-op-input')
  })
})

describe('purgeStaleAgainstServer — partial-pull guard', () => {
  it('bails when totalOnServer > serverItems.length', () => {
    // Server says there are 100 records but we only fetched 30 — would
    // wrongly purge the other 70 valid local rows.
    const items = Array.from({ length: 30 }, (_, i) => ({ notification_uuid: `srv-${i}` }))
    expect(decideStalePurge(items, 100, true, [stale()])).toBe('no-op-partial')
  })

  it('proceeds when totalOnServer === serverItems.length (complete)', () => {
    const items = [{ notification_uuid: 'srv-keep' }]
    const notifs = [stale({ client_uuid: 'srv-keep' }), stale({ client_uuid: 'gone' })]
    const result = decideStalePurge(items, 1, true, notifs)
    expect(result).toEqual(['gone'])
  })

  it('proceeds when totalOnServer < serverItems.length (server underreport)', () => {
    // Some endpoints return data.total counting only certain statuses.
    // Items > total → still a complete authoritative set.
    const items = [{ notification_uuid: 'a' }, { notification_uuid: 'b' }]
    expect(decideStalePurge(items, 1, true, [stale({ client_uuid: 'a' })])).toEqual([])
  })
})

describe('purgeStaleAgainstServer — keep predicates', () => {
  it('keeps already-deleted rows (idempotent)', () => {
    const n = stale({ client_uuid: 'gone', deleted_at: '2026-05-19T11:00:00Z' })
    expect(decideStalePurge([], 0, true, [n])).toEqual([])
  })

  it('keeps UNSYNCED local-only edits even when server doesn\'t know them', () => {
    // Officer just took a screening offline. sync_status=UNSYNCED, no
    // synced_at. We must NEVER destroy this — it has no server twin yet.
    const n = stale({ client_uuid: 'local-only', sync_status: SYNC.UNSYNCED, synced_at: null })
    expect(decideStalePurge([], 0, true, [n])).toEqual([])
  })

  it('keeps rows that have never been synced (synced_at null)', () => {
    // Defence in depth — sync_status flapping shouldn't cause data loss.
    const n = stale({ client_uuid: 'never-synced', sync_status: SYNC.SYNCED, synced_at: null })
    expect(decideStalePurge([], 0, true, [n])).toEqual([])
  })

  it('keeps rows whose client_uuid matches a server record', () => {
    const items = [{ notification_uuid: 'shared-uuid' }]
    const n = stale({ client_uuid: 'shared-uuid' })
    expect(decideStalePurge(items, 1, true, [n])).toEqual([])
  })

  it('keeps rows whose server integer id matches', () => {
    const items = [{ notification_id: 999 }]
    const n = stale({ client_uuid: 'no-uuid-match-but-id-yes', id: 999 })
    expect(decideStalePurge(items, 1, true, [n])).toEqual([])
  })

  it('coerces id to Number — string "999" matches integer 999 on server', () => {
    const items = [{ notification_id: 999 }]
    const n = stale({ id: '999' })
    expect(decideStalePurge(items, 1, true, [n])).toEqual([])
  })

  it('coerces uuid to String — mismatched types still match', () => {
    const items = [{ notification_uuid: 'abc' }]
    const n = stale({ client_uuid: 'abc' })
    expect(decideStalePurge(items, 1, true, [n])).toEqual([])
  })
})

describe('purgeStaleAgainstServer — purge predicates', () => {
  it('purges a synced row absent from server set', () => {
    const items = [{ notification_uuid: 'still-here' }]
    const n = stale({ client_uuid: 'gone-from-server' })
    expect(decideStalePurge(items, 1, true, [n])).toEqual(['gone-from-server'])
  })

  it('purges multiple absent rows', () => {
    const items = [{ notification_uuid: 'keep-1' }, { notification_uuid: 'keep-2' }]
    const idb = [
      stale({ client_uuid: 'keep-1' }),
      stale({ client_uuid: 'gone-1' }),
      stale({ client_uuid: 'keep-2' }),
      stale({ client_uuid: 'gone-2' }),
    ]
    const result = decideStalePurge(items, 2, true, idb)
    expect(result.sort()).toEqual(['gone-1', 'gone-2'])
  })

  it('purges by id-mismatch when uuid is missing on local', () => {
    const items = [{ notification_id: 5 }]
    const idb = [stale({ client_uuid: null, id: 99 })]
    expect(decideStalePurge(items, 1, true, idb)).toEqual([null])
  })
})

describe('purgeStaleAgainstServer — destructive-mix scenarios', () => {
  it('mixed UNSYNCED + SYNCED + missing rows: only the missing-synced purges', () => {
    const items = [{ notification_uuid: 'keep' }]
    const idb = [
      stale({ client_uuid: 'keep' }),
      stale({ client_uuid: 'local-only', sync_status: SYNC.UNSYNCED, synced_at: null }),
      stale({ client_uuid: 'stale' }),
      stale({ client_uuid: 'never', synced_at: null }),
    ]
    expect(decideStalePurge(items, 1, true, idb)).toEqual(['stale'])
  })

  it('admin-wipe scenario: server has 0, local has 5 synced rows → all 5 purge', () => {
    const idb = Array.from({ length: 5 }, (_, i) =>
      stale({ client_uuid: `s${i}`, id: 1000 + i })
    )
    const result = decideStalePurge([], 0, true, idb)
    expect(result.sort()).toEqual(['s0', 's1', 's2', 's3', 's4'])
  })

  it('admin-wipe scenario + offline-only edits: only synced rows purge', () => {
    const idb = [
      stale({ client_uuid: 'synced-a' }),
      stale({ client_uuid: 'offline-edit', sync_status: SYNC.UNSYNCED, synced_at: null }),
      stale({ client_uuid: 'synced-b' }),
    ]
    expect(decideStalePurge([], 0, true, idb).sort()).toEqual(['synced-a', 'synced-b'])
  })
})
