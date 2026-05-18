// Per-disease + invariant tests load the engine once via this helper.
// Side-effect import populates globalThis.window.DISEASES.

import '../_baseline/bootstrap-window.mjs';

export function getEngine() {
  if (!globalThis.window || !globalThis.window.DISEASES) {
    throw new Error('Engine not loaded — bootstrap-window.mjs failed.');
  }
  return globalThis.window.DISEASES;
}

export function topDiseaseFor(present, exposures = [], vitals = {}, absent = [], visited = []) {
  const D = getEngine();
  const r = D.getEnhancedScoreResult(present, absent, exposures, visited, vitals);
  return (r.top_diagnoses || [])[0]?.disease_id || null;
}

export function scoreFor(diseaseId, present, exposures = [], vitals = {}, absent = [], visited = []) {
  const D = getEngine();
  const r = D.getEnhancedScoreResult(present, absent, exposures, visited, vitals);
  const all = [...(r.top_diagnoses || []), ...(r.all_reportable || [])];
  const hit = all.find(d => d.disease_id === diseaseId);
  return hit ? hit.final_score : 0;
}

export function fullResult(present, exposures = [], vitals = {}, absent = [], visited = [], context = {}) {
  const D = getEngine();
  return D.getEnhancedScoreResult(present, absent, exposures, visited, vitals, context);
}
