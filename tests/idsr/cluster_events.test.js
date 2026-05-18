import { describe, it, expect, beforeAll } from 'vitest';
import { getEngine, fullResult } from './_helpers/loadEngine.mjs';

let D;
beforeAll(() => { D = getEngine(); });

describe('Cluster-event scoring branch', () => {
  it('cluster_of_deaths is in catalog with category=event', () => {
    const e = D.diseases.find(d => d.id === 'cluster_of_deaths');
    expect(e).toBeTruthy();
    expect(e.category).toBe('event');
  });

  it('cluster_similar_symptoms is in catalog with category=event', () => {
    const e = D.diseases.find(d => d.id === 'cluster_similar_symptoms');
    expect(e).toBeTruthy();
    expect(e.category).toBe('event');
  });

  it('public_health_event_unknown is in catalog with category=event', () => {
    const e = D.diseases.find(d => d.id === 'public_health_event_unknown');
    expect(e).toBeTruthy();
    expect(e.category).toBe('event');
  });

  it('cluster events do NOT surface when no flag is set', () => {
    const r = fullResult(['fever', 'cough'], []);
    const eventTops = (r.top_diagnoses || []).filter(d =>
      ['cluster_of_deaths', 'cluster_similar_symptoms', 'public_health_event_unknown'].includes(d.disease_id)
    );
    expect(eventTops).toEqual([]);
  });

  it('cluster_of_deaths surfaces when cluster_deaths_in_community flag is true', () => {
    const r = D.getEnhancedScoreResult(['fever'], [], [], [], {}, {
      clinical_context: { cluster_deaths_in_community: true },
    });
    const events = (r.all_reportable || []).filter(d => d.disease_id === 'cluster_of_deaths');
    if (events.length === 0) {
      // R2 caps top to 1; check raw scoring path also.
      const raw = D.scoreDiseases(['fever'], [], [], { clinical_context: { cluster_deaths_in_community: true } });
      const rawHit = (raw.all_reportable || []).find(d => d.disease_id === 'cluster_of_deaths')
                  || (raw.top_diagnoses || []).find(d => d.disease_id === 'cluster_of_deaths');
      expect(rawHit, 'cluster_of_deaths should appear when flag is true').toBeTruthy();
    } else {
      expect(events.length).toBeGreaterThan(0);
    }
  });
});
