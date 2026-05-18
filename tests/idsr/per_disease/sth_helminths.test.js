// Soil-transmitted helminths (STH): ascariasis, trichuriasis, ancylostomiasis,
// strongyloidiasis. Plus schistosomiasis (urinary + intestinal). All are
// IDSR Annex-1A eradication/elimination or other-major-public-health entries.

import { describe, it, expect } from 'vitest';
import { getEngine } from '../_helpers/loadEngine.mjs';

describe('IDSR helminth diseases — catalog presence + thresholds', () => {
  const ids = [
    'ascariasis', 'trichuriasis', 'ancylostomiasis', 'strongyloidiasis',
    'schistosomiasis_urinary', 'schistosomiasis_intestinal',
  ];

  it('all 6 helminth IDs present', () => {
    const D = getEngine();
    const set = new Set(D.diseases.map(d => d.id));
    for (const id of ids) expect(set.has(id), `${id} missing`).toBe(true);
  });

  it('every helminth has alert_threshold + epidemic_threshold strings', () => {
    const D = getEngine();
    for (const id of ids) {
      const d = D.diseases.find(x => x.id === id);
      expect(typeof d.alert_threshold).toBe('string');
      expect(d.alert_threshold.length).toBeGreaterThan(0);
      expect(typeof d.epidemic_threshold).toBe('string');
    }
  });
});
