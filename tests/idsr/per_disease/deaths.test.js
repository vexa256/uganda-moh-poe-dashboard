// Death-event IDSR diseases: maternal_death, perinatal_death, under_five_death.
// These are surveillance entities — vignette inputs are demographic/event
// flags rather than symptom hallmarks. The test pins catalog presence +
// minimum metadata.

import { describe, it, expect } from 'vitest';
import { getEngine } from '../_helpers/loadEngine.mjs';

describe('IDSR death-event surveillance diseases', () => {
  const ids = ['maternal_death', 'perinatal_death', 'under_five_death'];

  it('all 3 death-event entities are active diseases (not events)', () => {
    const D = getEngine();
    for (const id of ids) {
      const d = D.diseases.find(x => x.id === id);
      expect(d, `${id} missing`).toBeTruthy();
      expect(d.category).toBe('disease');
    }
  });

  it('every death entity has idsr_source_ref and alert_threshold', () => {
    const D = getEngine();
    for (const id of ids) {
      const d = D.diseases.find(x => x.id === id);
      expect(d.idsr_source_ref).toBeTruthy();
      expect(d.alert_threshold).toBeTruthy();
    }
  });
});
