/**
 * Per-page-load FIFO mutex for syncCaseToServer.
 *
 * Bug reproduced: multiple fire-and-forget syncCaseToServer calls (step 3,
 * step 5 disposition, alert-create branch) could overlap. Each builds its
 * own payload from the IDB at its own start time. If the network/server
 * reorders the POSTs, an EARLIER-CREATED payload with stale data can
 * arrive AFTER a later one — silently erasing the disposition's 3 diseases
 * and reverting case_status to IN_PROGRESS.
 *
 * The mutex chains every call through a single promise so they execute
 * one-at-a-time in invocation order. We mirror the production mutex logic
 * here and assert ordering, deadlock safety, and error isolation.
 */
import { describe, it, expect } from 'vitest'

function makeMutex() {
  let chain = Promise.resolve()
  return async function run(taskFn) {
    const prev = chain
    let release
    chain = new Promise(r => { release = r })
    try {
      try { await prev } catch (_) { /* prior errors don't poison us */ }
      return await taskFn()
    } finally {
      release()
    }
  }
}

describe('syncCaseToServer mutex — ordering', () => {
  it('runs sequential calls in FIFO order even when later tasks are FASTER', async () => {
    const run = makeMutex()
    const order = []
    const a = run(async () => { await new Promise(r => setTimeout(r, 30)); order.push('a-slow') })
    const b = run(async () => { await new Promise(r => setTimeout(r, 5));  order.push('b-fast') })
    const c = run(async () => { order.push('c-instant') })
    await Promise.all([a, b, c])
    expect(order).toEqual(['a-slow', 'b-fast', 'c-instant'])
  })

  it('reproduces the disposition race fix: step-3 always completes before step-5 lands', async () => {
    // step-3 sync starts at t=0 with [empty diseases] payload.
    // step-5 disposition fires at t=2 with [3 diseases] payload.
    // Without mutex, step-5 (fast network) could land first then step-3
    // erases the 3 diseases. With mutex, step-3 blocks step-5 entirely.
    const run = makeMutex()
    let serverState = 'init'
    const step3 = run(async () => {
      await new Promise(r => setTimeout(r, 50))  // slow Step-3 POST
      serverState = 'step3-empty-diseases'
    })
    // Step 5 fires while Step 3 is still in-flight
    const step5 = run(async () => {
      // Step 5's payload is built JUST BEFORE this body runs (after step3
      // releases the mutex). So even if step3's wire result lands first
      // on the server, the client only sends step5 AFTER step3 completes.
      serverState = 'step5-3-diseases'
    })
    await Promise.all([step3, step5])
    // Final state IS step5's, because it ran after step3 in client order.
    expect(serverState).toBe('step5-3-diseases')
  })
})

describe('syncCaseToServer mutex — deadlock safety', () => {
  it('does not deadlock when a prior task throws', async () => {
    const run = makeMutex()
    const failed = run(async () => { throw new Error('phase2 timeout') })
    await failed.catch(() => {})    // prior call errors are isolated
    const next = run(async () => 'ok')
    await expect(next).resolves.toBe('ok')
  })

  it('releases the lock when the task body throws synchronously', async () => {
    const run = makeMutex()
    const bad = run(() => { throw new Error('sync throw') })
    await bad.catch(() => {})
    const good = run(async () => 'recovered')
    await expect(good).resolves.toBe('recovered')
  })

  it('releases the lock when the task rejects asynchronously', async () => {
    const run = makeMutex()
    const bad = run(async () => { throw new Error('async throw') })
    await bad.catch(() => {})
    const good = run(async () => 'recovered')
    await expect(good).resolves.toBe('recovered')
  })

  it('many sequential tasks complete without leaking promises', async () => {
    const run = makeMutex()
    const ticks = []
    const tasks = []
    for (let i = 0; i < 30; i++) {
      tasks.push(run(async () => { ticks.push(i) }))
    }
    await Promise.all(tasks)
    expect(ticks).toEqual(Array.from({ length: 30 }, (_, i) => i))
  })
})

describe('syncCaseToServer mutex — error isolation', () => {
  it('successor tasks see clean state after predecessor failure', async () => {
    // Race scenario: t1 fails, t2 + t3 must STILL execute in order behind
    // it (the mutex release in finally MUST fire even on throw). We
    // verify by checking that the success markers from t2/t3 land in
    // call order — the failure marker may interleave (it lives in an
    // external .catch handler outside the mutex, which is fine).
    const run = makeMutex()
    const order = []
    const t1 = run(async () => { order.push('t1-start'); throw new Error('boom') })
    const t2 = run(async () => { order.push('t2') })
    const t3 = run(async () => { order.push('t3') })
    await Promise.all([t1.catch(() => {}), t2, t3])
    // Strict ordering of the mutex-protected bodies:
    expect(order.indexOf('t1-start')).toBeLessThan(order.indexOf('t2'))
    expect(order.indexOf('t2')).toBeLessThan(order.indexOf('t3'))
    // And every body ran (no deadlock):
    expect(order).toContain('t1-start')
    expect(order).toContain('t2')
    expect(order).toContain('t3')
  })
})
