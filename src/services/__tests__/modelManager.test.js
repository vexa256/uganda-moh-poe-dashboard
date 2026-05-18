/**
 * Model Manager unit tests.
 *
 * Covers:
 *   - Registry shape (frozen, required ids, field shape)
 *   - Cellular-safety flags for translate pairs + built-ins
 *   - ensureModel gating (unknown id, master off, freeze on, bad input)
 *   - listModels + getTelemetry don't throw
 *   - disposeAll (audit fix D5.01)
 */
import { beforeEach, describe, it, expect } from 'vitest'
import {
  MODEL_REGISTRY, MODEL_STATUS,
  ensureModel, listModels, getTelemetry, disposeAll,
} from '../modelManager.js'
import { CAPABILITY_KEYS, setEnabled } from '../capabilities.js'

beforeEach(() => {
  localStorage.clear()
  // Default: master on, freeze off. That's the app default but be explicit
  // so tests don't depend on order.
  setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, true)
  setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, false)
})

describe('MODEL_REGISTRY — shape', () => {
  it('is frozen', () => {
    expect(Object.isFrozen(MODEL_REGISTRY)).toBe(true)
  })

  const EXPECTED_IDS = [
    'mrz-latin',
    'doc-scanner',
    'face-detection',
    'face-embedding-facenet',
    'translate-en-pt',
    'translate-en-fr',
    'translate-en-bem',
    'entity-extraction',
    'smart-reply',
  ]

  it('contains every expected id', () => {
    for (const id of EXPECTED_IDS) {
      expect(MODEL_REGISTRY[id], `missing registry id: ${id}`).toBeTruthy()
    }
  })

  it('every entry has the expected shape', () => {
    for (const id of EXPECTED_IDS) {
      const e = MODEL_REGISTRY[id]
      expect(e.id).toBe(id)
      expect(typeof e.label).toBe('string')
      expect(typeof e.sizeMb).toBe('number')
      expect(typeof e.feature).toBe('string')
      expect(typeof e.cellularSafe).toBe('boolean')
      expect(typeof e.downloader).toBe('string')
      expect(Array.isArray(e.required_for)).toBe(true)
    }
  })
})

describe('MODEL_REGISTRY — cellular safety', () => {
  it('translate pairs are cellular-unsafe (>= 30 MB)', () => {
    expect(MODEL_REGISTRY['translate-en-pt'].cellularSafe).toBe(false)
    expect(MODEL_REGISTRY['translate-en-fr'].cellularSafe).toBe(false)
    expect(MODEL_REGISTRY['translate-en-bem'].cellularSafe).toBe(false)
  })

  it('smart-reply and doc-scanner are cellular-safe (0-byte built-ins)', () => {
    expect(MODEL_REGISTRY['smart-reply'].cellularSafe).toBe(true)
    expect(MODEL_REGISTRY['smart-reply'].sizeMb).toBe(0)
    expect(MODEL_REGISTRY['doc-scanner'].cellularSafe).toBe(true)
    expect(MODEL_REGISTRY['doc-scanner'].sizeMb).toBe(0)
  })
})

describe('ensureModel — gating', () => {
  it('unknown id returns { ok:false, reason:"unknown-model" }', async () => {
    const r = await ensureModel('no-such-model')
    expect(r).toEqual({ ok: false, reason: 'unknown-model' })
  })

  it('never throws on garbage input', async () => {
    await expect(ensureModel(null)).resolves.toBeDefined()
    await expect(ensureModel(undefined)).resolves.toBeDefined()
    await expect(ensureModel(42)).resolves.toBeDefined()
    await expect(ensureModel({})).resolves.toBeDefined()
    await expect(ensureModel([])).resolves.toBeDefined()
  })

  it('every garbage input returns unknown-model', async () => {
    for (const x of [null, undefined, 42, {}, []]) {
      const r = await ensureModel(x)
      expect(r.ok).toBe(false)
      expect(r.reason).toBe('unknown-model')
    }
  })

  it('master off returns sentinel-master-off', async () => {
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    const r = await ensureModel('mrz-latin')
    expect(r).toEqual({ ok: false, reason: 'sentinel-master-off' })
  })

  it('freeze on returns downloads-frozen', async () => {
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, true)
    const r = await ensureModel('mrz-latin')
    expect(r).toEqual({ ok: false, reason: 'downloads-frozen' })
  })
})

describe('listModels', () => {
  it('returns an iterable', () => {
    const arr = listModels()
    expect(Array.isArray(arr)).toBe(true)
    expect(arr.length).toBeGreaterThan(0)
  })

  it('contains mrz-latin and face-detection', () => {
    const ids = listModels().map(m => m.id)
    expect(ids).toContain('mrz-latin')
    expect(ids).toContain('face-detection')
  })
})

describe('getTelemetry', () => {
  it('returns a non-throwing object with expected shape', () => {
    const t = getTelemetry()
    expect(t).toBeTruthy()
    expect(typeof t.totalBytesDownloaded).toBe('number')
    expect(Array.isArray(t.modelsReady)).toBe(true)
    expect(Array.isArray(t.modelsEvicted)).toBe(true)
    expect(Array.isArray(t.currentDownloads)).toBe(true)
    expect(Array.isArray(t.errorsLast24h)).toBe(true)
  })
})

describe('disposeAll — audit fix D5.01', () => {
  it('is callable and does not throw', () => {
    expect(() => disposeAll()).not.toThrow()
  })

  it('is idempotent — calling twice does not throw', () => {
    expect(() => { disposeAll(); disposeAll() }).not.toThrow()
  })
})

describe('MODEL_STATUS enum', () => {
  it('exposes the expected status constants', () => {
    expect(MODEL_STATUS.unknown).toBe('unknown')
    expect(MODEL_STATUS.missing).toBe('missing')
    expect(MODEL_STATUS.queued).toBe('queued')
    expect(MODEL_STATUS.downloading).toBe('downloading')
    expect(MODEL_STATUS.ready).toBe('ready')
    expect(MODEL_STATUS.error).toBe('error')
    expect(MODEL_STATUS.evicted).toBe('evicted')
  })
})
