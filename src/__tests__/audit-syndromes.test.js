// @vitest-environment jsdom
import { describe, it, expect, beforeAll } from 'vitest'
beforeAll(async () => {
  await import('../Diseases.js')
  await import('../Diseases_intelligence.js')
})
describe('every disease maps to a WHO syndrome', () => {
  it('has no orphan syndromes', () => {
    const D = globalThis.window.DISEASES
    const map = D.engine_to_who_syndrome || {}
    const missing = []
    for (const d of D.diseases) {
      const syns = d.syndromes || []
      const mapped = syns.find(s => map[s])
      if (!mapped) missing.push({ id: d.id, syndromes: syns })
    }
    if (missing.length) console.log('MISSING:', JSON.stringify(missing, null, 2))
    expect(missing).toEqual([])
  })
})
