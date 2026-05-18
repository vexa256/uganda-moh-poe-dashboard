// Combined coverage for IDSR Annex 1A skin-anchored neglected diseases:
// leprosy, yaws, onchocerciasis, dracunculiasis, lymphatic_filariasis,
// trachoma, trypanosomiasis, neonatal_tetanus.
//
// These are eradication/elimination targets; surveillance vignettes expect
// the IDSR-correct ID (or an acceptable IDSR-aligned alternative) at top.

import { describe, it, expect } from 'vitest';
import { getEngine } from '../_helpers/loadEngine.mjs';

describe('IDSR eradication/elimination skin & neglected diseases — catalog presence', () => {
  it('all 8 entities exist in the active catalog', () => {
    const D = getEngine();
    const ids = new Set(D.diseases.map(d => d.id));
    for (const id of [
      'leprosy', 'yaws', 'onchocerciasis', 'dracunculiasis',
      'lymphatic_filariasis', 'trachoma', 'trypanosomiasis',
      'neonatal_tetanus',
    ]) {
      expect(ids.has(id), `${id} missing from active catalog`).toBe(true);
    }
  });

  it('every entity has idsr_source_ref + alert_threshold + epidemic_threshold', () => {
    const D = getEngine();
    for (const id of [
      'leprosy', 'yaws', 'onchocerciasis', 'dracunculiasis',
      'lymphatic_filariasis', 'trachoma', 'trypanosomiasis',
      'neonatal_tetanus',
    ]) {
      const d = D.diseases.find(x => x.id === id);
      expect(d.idsr_source_ref, `${id}.idsr_source_ref`).toBeTruthy();
      expect(d.alert_threshold, `${id}.alert_threshold`).toBeTruthy();
      expect(d.epidemic_threshold, `${id}.epidemic_threshold`).toBeTruthy();
    }
  });
});
