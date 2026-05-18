import { describe, it, expect, beforeAll } from 'vitest';
import { getEngine } from './_helpers/loadEngine.mjs';

let D;
beforeAll(() => { D = getEngine(); });

const legacyIds = [
  'mpox', 'nipah_virus', 'hantavirus', 'mers',
  'hepatitis_a', 'hepatitis_e', 'tularemia',
  'rickettsia_scrub_typhus', 'leptospirosis',
  'japanese_encephalitis', 'west_nile_fever',
];

describe('Legacy disease_code rendering', () => {
  it.each(legacyIds)('%s resolves to a legacy stub with name + idsr_source_ref null', (id) => {
    const d = D.getDiseaseById(id, { includeLegacy: true });
    expect(d, `${id} should resolve`).toBeTruthy();
    expect(d.id).toBe(id);
    expect(typeof d.name).toBe('string');
    expect(d.name).toMatch(/legacy/);
    expect(d.idsr_source_ref).toBeNull();
  });

  it.each(legacyIds)('%s carries who_case_definition for historical display', (id) => {
    const d = D.getDiseaseById(id, { includeLegacy: true });
    expect(d.who_case_definition, `${id} should have who_case_definition`).toBeTruthy();
  });

  it('legacy entries are NOT in the active diseases list', () => {
    const activeIds = new Set(D.diseases.map(d => d.id));
    for (const id of legacyIds) {
      expect(activeIds.has(id), `legacy ${id} must not be in diseases[]`).toBe(false);
    }
  });
});
