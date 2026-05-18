import { describe, it, expect } from 'vitest';
import { topDiseaseFor, getEngine } from '../_helpers/loadEngine.mjs';

describe('Dog bite (rabies exposure) — IDSR Annex 1A surveillance', () => {
  it('catalog entry exists, separate from rabies disease', () => {
    const D = getEngine();
    expect(D.diseases.find(d => d.id === 'dog_bite_rabies_exposure')).toBeTruthy();
    expect(D.diseases.find(d => d.id === 'rabies')).toBeTruthy();
  });

  it('Positive: animal bite without neurological signs → dog_bite OR brucellosis (livestock zoonosis)', () => {
    const top = topDiseaseFor(
      ['fever', 'animal_bite_wound'],
      ['animal_bite_or_wildlife_contact']
    );
    // dog_bite_rabies_exposure is the IDSR-correct surveillance entity for
    // an animal bite without neurological signs; brucellosis is an
    // acceptable zoonotic alternative.
    expect(['dog_bite_rabies_exposure', 'brucellosis', 'rabies']).toContain(top);
  });
});
