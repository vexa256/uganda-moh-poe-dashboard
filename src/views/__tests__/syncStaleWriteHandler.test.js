/**
 * Edge-case tests for the stale_write handler in
 * SecondaryScreening.vue → syncCaseToServer().
 *
 * The handler is closure-scoped inside the SFC; we mirror the pure
 * decision logic here. The key contract under test:
 *
 *   When the server returns meta.stale_write=true (our v→v skew condition):
 *     1. KEEP the user's local case fields (don't overlay server's older state)
 *     2. BUMP record_version to stored_version+1 (so the next push wins)
 *     3. FLIP sync_status back to UNSYNCED (so the engine retries)
 *     4. Re-stamp updated_at
 *
 *   When stale_write is false / absent: standard SYNCED path runs and
 *   bumps record_version by 1 from the current local value.
 *
 *   No infinite loops: a single bump produces a record_version that is
 *   strictly greater than the server's stored_version. Next push satisfies
 *   "incoming > stored" and the server applies the update.
 */
import { describe, it, expect } from 'vitest'

const SYNC = { UNSYNCED: 'UNSYNCED', SYNCED: 'SYNCED', FAILED: 'FAILED' }

/**
 * Pure mirror of the staleWrite branch in syncCaseToServer.
 * Returns the next IDB write payload that would be produced.
 */
function decideStaleWriteReattempt({ caseRecord, serverResponse, serverId }) {
  const sc          = serverResponse.data ?? {}
  const meta        = serverResponse.meta ?? {}
  const staleWrite  = meta.stale_write === true
  // Mirror the production fallback chain.
  const storedVer   = Number(
    meta.stored_version ?? sc.record_version ?? caseRecord.record_version ?? 1
  )

  if (staleWrite) {
    return {
      branch: 'stale-reattempt',
      payload: {
        ...caseRecord,
        id: sc.id ?? serverId,
        server_id: sc.id ?? serverId,
        record_version: storedVer + 1,
        sync_status: SYNC.UNSYNCED,
        // updated_at is non-deterministic in production — exclude.
      },
    }
  }
  return {
    branch: 'synced',
    payload: {
      ...caseRecord,
      id: sc.id ?? serverId,
      server_id: sc.id ?? serverId,
      sync_status: SYNC.SYNCED,
      last_synced_record_version: (caseRecord.record_version || 1) + 1,
      record_version: (caseRecord.record_version || 1) + 1,
    },
  }
}

describe('stale_write handler — happy path (no skew)', () => {
  it('marks SYNCED and bumps record_version by 1 when meta.stale_write is absent', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', case_status: 'CLOSED', record_version: 5 },
      serverResponse: { data: { id: 99, case_status: 'CLOSED' }, meta: {} },
      serverId: 99,
    })
    expect(result.branch).toBe('synced')
    expect(result.payload.sync_status).toBe(SYNC.SYNCED)
    expect(result.payload.record_version).toBe(6)
    expect(result.payload.case_status).toBe('CLOSED')   // local intent preserved
  })

  it('treats meta.stale_write=false as not-stale', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', case_status: 'CLOSED', record_version: 5 },
      serverResponse: { data: { id: 99 }, meta: { stale_write: false } },
      serverId: 99,
    })
    expect(result.branch).toBe('synced')
  })

  it('treats truthy-but-not-true (e.g. 1) as not-stale (strict === comparison)', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', case_status: 'CLOSED', record_version: 5 },
      serverResponse: { data: { id: 99 }, meta: { stale_write: 1 } },
      serverId: 99,
    })
    expect(result.branch).toBe('synced')
  })
})

describe('stale_write handler — stale write reattempt', () => {
  it('preserves the user\'s CLOSED intent (does NOT overlay server\'s older case_status)', () => {
    // Scenario: user closed locally at v=15. Server is at v=20 with
    // case_status=IN_PROGRESS (older state survived because of an earlier
    // truncation rollback). If we overlaid server state, the close would
    // silently disappear. Instead we keep CLOSED and bump version.
    const result = decideStaleWriteReattempt({
      caseRecord: {
        client_uuid: 'c1',
        case_status: 'CLOSED',
        syndrome_classification: 'ACUTE_HAEMORRHAGIC_FEVER',
        risk_level: 'CRITICAL',
        officer_notes: 'critical hemorrhagic case',
        record_version: 15,
      },
      serverResponse: {
        data: { id: 99, case_status: 'IN_PROGRESS', record_version: 20 },
        meta: { stale_write: true, stored_version: 20 },
      },
      serverId: 99,
    })
    expect(result.branch).toBe('stale-reattempt')
    expect(result.payload.case_status).toBe('CLOSED')                          // ✓ user's intent kept
    expect(result.payload.syndrome_classification).toBe('ACUTE_HAEMORRHAGIC_FEVER')
    expect(result.payload.risk_level).toBe('CRITICAL')
    expect(result.payload.officer_notes).toBe('critical hemorrhagic case')
  })

  it('bumps record_version to stored_version + 1 so next push wins', () => {
    // Critical invariant: post-bump version MUST be > stored_version, or
    // we'd loop forever in stale_write hell.
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', case_status: 'CLOSED', record_version: 15 },
      serverResponse: { data: { id: 99 }, meta: { stale_write: true, stored_version: 42 } },
      serverId: 99,
    })
    expect(result.payload.record_version).toBe(43)
    expect(result.payload.record_version).toBeGreaterThan(42)   // explicit anti-loop assertion
  })

  it('flips sync_status to UNSYNCED so the engine picks up the retry', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', case_status: 'CLOSED', record_version: 15, sync_status: SYNC.SYNCED },
      serverResponse: { data: { id: 99 }, meta: { stale_write: true, stored_version: 20 } },
      serverId: 99,
    })
    expect(result.payload.sync_status).toBe(SYNC.UNSYNCED)
  })

  it('uses meta.stored_version when present (most authoritative)', () => {
    // Fallback chain: meta.stored_version → sc.record_version → local → 1
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: {
        data: { id: 99, record_version: 100 },
        meta: { stale_write: true, stored_version: 200 },   // wins
      },
      serverId: 99,
    })
    expect(result.payload.record_version).toBe(201)
  })

  it('falls back to sc.record_version when meta.stored_version is missing', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: {
        data: { id: 99, record_version: 100 },
        meta: { stale_write: true },     // no stored_version
      },
      serverId: 99,
    })
    expect(result.payload.record_version).toBe(101)
  })

  it('falls back to local record_version when server returns none', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: { data: { id: 99 }, meta: { stale_write: true } },
      serverId: 99,
    })
    expect(result.payload.record_version).toBe(6)
  })

  it('final fallback to 1 when nothing is set anywhere', () => {
    const result = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1' },
      serverResponse: { data: {}, meta: { stale_write: true } },
      serverId: 99,
    })
    expect(result.payload.record_version).toBe(2)   // 1 + 1
  })

  it('keeps server_id from response and falls back to passed-in serverId', () => {
    const a = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: { data: { id: 77 }, meta: { stale_write: true, stored_version: 5 } },
      serverId: 99,
    })
    expect(a.payload.id).toBe(77)        // server-returned wins
    expect(a.payload.server_id).toBe(77)

    const b = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: { data: {}, meta: { stale_write: true, stored_version: 5 } },
      serverId: 99,
    })
    expect(b.payload.id).toBe(99)        // fallback to passed-in
  })
})

describe('stale_write handler — convergence (no infinite loop)', () => {
  it('one bump → next push satisfies incomingVer > stored on server', () => {
    // Simulate the full round-trip: user pushes at v=5, server has v=20,
    // server returns stale_write. After bump, push v=21. Server stored=20
    // → 21 > 20 → success.
    const round1 = decideStaleWriteReattempt({
      caseRecord: { client_uuid: 'c1', record_version: 5 },
      serverResponse: { data: { id: 99 }, meta: { stale_write: true, stored_version: 20 } },
      serverId: 99,
    })
    expect(round1.payload.record_version).toBe(21)

    // Mobile pushes again. Server now sees incomingVer=21 > stored=20 →
    // applies fields. No stale_write in response. Synced branch.
    const round2 = decideStaleWriteReattempt({
      caseRecord: round1.payload,
      serverResponse: { data: { id: 99, record_version: 21 }, meta: { case_updated: true } },
      serverId: 99,
    })
    expect(round2.branch).toBe('synced')
    expect(round2.payload.sync_status).toBe(SYNC.SYNCED)
    expect(round2.payload.record_version).toBe(22)
  })

  it('three consecutive stale_writes still converge (no oscillation)', () => {
    // Pathological: every retry hits stale_write because the version
    // racing keeps pushing server ahead. After each round our local
    // version must STRICTLY INCREASE. Convergence guaranteed because
    // bumping is monotonic.
    let local = { client_uuid: 'c1', record_version: 5 }
    let storedOnServer = 10
    const versions = [local.record_version]
    for (let i = 0; i < 3; i++) {
      const r = decideStaleWriteReattempt({
        caseRecord: local,
        serverResponse: {
          data: { id: 99, record_version: storedOnServer },
          meta: { stale_write: true, stored_version: storedOnServer },
        },
        serverId: 99,
      })
      local = r.payload
      versions.push(local.record_version)
      storedOnServer += 5   // someone else keeps pushing too
    }
    // Strict monotonic increase
    for (let i = 1; i < versions.length; i++) {
      expect(versions[i]).toBeGreaterThan(versions[i - 1])
    }
    // After 3 rounds the local version is well past the initial 5
    expect(local.record_version).toBeGreaterThanOrEqual(11)
  })
})
