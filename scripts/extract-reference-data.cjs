#!/usr/bin/env node
/**
 * Phase REF · Unit REF-2 — JS → JSON reference-data extractor.
 *
 * Loads the four authoritative JS reference files inside a sandboxed VM
 * (with a `window` shim and a `console` stub), then dumps the relevant
 * globals + the inner ENDEMIC_COUNTRIES oracle to JSON files under
 * api/database/seeders/data/.
 *
 * The PHP seeders (REF-2 step 2) read these JSON files verbatim — that
 * way the parity test (REF-6) can byte-equal compare DB rows back to
 * these JSON snapshots.
 *
 * Usage:
 *   node scripts/extract-reference-data.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoRoot = path.resolve(__dirname, '..');
const srcDir   = path.join(repoRoot, 'src');
const outDir   = path.join(repoRoot, 'api', 'database', 'seeders', 'data');

fs.mkdirSync(outDir, { recursive: true });

// ---- Sandbox -----------------------------------------------------------
const sandbox = {
    window: {},
    console: { log: () => {}, warn: () => {}, error: () => {}, info: () => {}, debug: () => {} },
};
vm.createContext(sandbox);

// Capture `const ENDEMIC_COUNTRIES = {...}` from inside Diseases_intelligence.js.
// The oracle is in an IIFE-local scope, so we patch the file body to also
// hang it on `window.__ENDEMIC_COUNTRIES__` for extraction purposes only.
function loadFile(name) {
    const file = path.join(srcDir, name);
    let code = fs.readFileSync(file, 'utf8');

    // Surgical patch — only applied to the intelligence file.
    if (name === 'Diseases_intelligence.js') {
        // Insert window.__ENDEMIC_COUNTRIES__ = ENDEMIC_COUNTRIES;
        // immediately after the ENDEMIC_COUNTRIES literal closes.  We
        // anchor on the next non-blank `};` after the definition line.
        const anchor = 'const ENDEMIC_COUNTRIES = {';
        const idx = code.indexOf(anchor);
        if (idx === -1) {
            throw new Error('Could not locate ENDEMIC_COUNTRIES definition in Diseases_intelligence.js');
        }
        // Walk balanced braces from the opening `{` of ENDEMIC_COUNTRIES.
        let i = idx + anchor.length - 1; // position of `{`
        let depth = 0;
        for (; i < code.length; i++) {
            if (code[i] === '{') depth++;
            else if (code[i] === '}') {
                depth--;
                if (depth === 0) { i++; break; } // i now points just past `}`
            }
        }
        // Find the trailing `;` after `}` (might have whitespace in between).
        while (i < code.length && code[i] !== ';') i++;
        if (i >= code.length) throw new Error('Could not find end of ENDEMIC_COUNTRIES const');
        const insertAt = i + 1;
        code = code.slice(0, insertAt)
             + '\n        window.__ENDEMIC_COUNTRIES__ = ENDEMIC_COUNTRIES;\n'
             + code.slice(insertAt);
    }

    vm.runInContext(code, sandbox, { filename: file });
}

// Order matches what the Vue main.ts loads in production.
loadFile('Diseases.js');
loadFile('Diseases_intelligence.js');
loadFile('exposures.js');
loadFile('POEs.js');

const w = sandbox.window;
if (!w.DISEASES)   throw new Error('window.DISEASES not populated by Diseases.js');
if (!w.EXPOSURES)  throw new Error('window.EXPOSURES not populated by exposures.js');
if (!w.POE_MAIN)   throw new Error('window.POE_MAIN not populated by POEs.js');
if (!w.__ENDEMIC_COUNTRIES__) {
    throw new Error('ENDEMIC_COUNTRIES sidecar not populated — patch in Diseases_intelligence.js failed');
}

// ---- Strip non-serialisable function fields off the diseases tree ------
function stripFunctions(value) {
    if (Array.isArray(value)) return value.map(stripFunctions);
    if (value && typeof value === 'object') {
        const out = {};
        for (const k of Object.keys(value)) {
            const v = value[k];
            if (typeof v === 'function') continue;
            out[k] = stripFunctions(v);
        }
        return out;
    }
    return value;
}

// ---- Disease records ---------------------------------------------------
const diseases = stripFunctions(w.DISEASES.diseases || []);

// ---- Engine config (everything on window.DISEASES *except* `diseases`) -
const engineConfig = {};
for (const k of Object.keys(w.DISEASES)) {
    if (k === 'diseases') continue;
    if (typeof w.DISEASES[k] === 'function') continue;
    engineConfig[k] = stripFunctions(w.DISEASES[k]);
}

// ---- Exposures ---------------------------------------------------------
const exposureTree = stripFunctions(w.EXPOSURES);

// ---- POEs --------------------------------------------------------------
const poeTree = stripFunctions(w.POE_MAIN);

// ---- Endemic countries oracle -----------------------------------------
const endemic = stripFunctions(w.__ENDEMIC_COUNTRIES__);

// ---- Symptom catalogue (derived from disease symptom_weights[]) -------
const symptomKeys = new Set();
for (const d of diseases) {
    if (d.symptom_weights && typeof d.symptom_weights === 'object') {
        Object.keys(d.symptom_weights).forEach(k => symptomKeys.add(k));
    }
    if (d.absent_hallmark_penalties && typeof d.absent_hallmark_penalties === 'object') {
        Object.keys(d.absent_hallmark_penalties).forEach(k => symptomKeys.add(k));
    }
    if (Array.isArray(d.hallmarks)) d.hallmarks.forEach(k => symptomKeys.add(k));
    if (d.gates) {
        for (const gateKey of ['required_all', 'required_any', 'soft_require_any', 'hard_fail_if_absent']) {
            if (Array.isArray(d.gates[gateKey])) {
                d.gates[gateKey].forEach(k => symptomKeys.add(k));
            }
        }
    }
}

// Tag each derived symptom with its sensitivity / hallmark / red-flag
// flags using counts across the disease set so the seeder can persist
// realistic defaults.
const symptomCatalogue = [];
const symptomHallmarkSet = new Set();
const symptomRedFlagKeys = new Set([
    'rash_vesicular_pustular', 'paralysis_acute_flaccid', 'bleeding',
    'hemorrhage', 'hemorrhagic_signs', 'jaundice', 'unexplained_bleeding',
    'shock', 'severe_dehydration', 'altered_consciousness',
]);
for (const d of diseases) {
    if (Array.isArray(d.hallmarks)) d.hallmarks.forEach(k => symptomHallmarkSet.add(k));
}
[...symptomKeys].sort().forEach(code => {
    // Ignore exposure codes that bled into gates.required_any (they have
    // their own catalogue).  Heuristic: an exposure code typically
    // contains "_exposure", "_contact", "travel_", "poultry", etc.  We
    // keep clinical symptoms only.
    const looksLikeExposure = /(_exposure$|^contact_|^close_contact|^travel_|^poultry|^laboratory_exposure|^affected_|^unvaccinated|^healthcare_exposure|^outbreak_)/.test(code);
    if (looksLikeExposure) return;
    symptomCatalogue.push({
        symptom_code:  code,
        display_name:  code.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
        category:      null,
        is_red_flag:   symptomRedFlagKeys.has(code),
        is_hallmark:   symptomHallmarkSet.has(code),
        sensitivity:   null,
    });
});

// ---- Exposure mappings (DB code → engine codes) -----------------------
const exposureMappings = [];
const exposureRows = [];
for (const e of (exposureTree.exposures || [])) {
    exposureRows.push({
        exposure_code: e.code || e.id,
        display_name:  e.label || e.display || e.code,
        category:      e.category || null,
        prompt_text:   e.prompt || e.question || null,
        response_type: e.response_type || 'YES_NO',
        is_high_risk:  Boolean(e.high_risk || e.is_high_risk),
        triggers_diseases: e.triggers || e.triggers_diseases || null,
        payload:       e,
    });
    const engineCodes = e.engine_codes || (e.engine_code ? [e.engine_code] : []);
    for (const ec of engineCodes) {
        exposureMappings.push({
            exposure_code: e.code || e.id,
            engine_code:   ec,
            priority:      0,
        });
    }
}

// ---- POE rows (flatten window.POE_MAIN.poes[]) ------------------------
// Country names in the source ('Zambia') are preserved verbatim into
// country_code for now; ISO-3 normalisation lands in a later REF unit.
const poeRows = [];
for (const p of (poeTree.poes || [])) {
    poeRows.push({
        country_code:        p.country || null,
        poe_code:            p.poe_code || p.poe_name || p.id,
        poe_name:            p.poe_name,
        admin_level_1:       p.admin_level_1 || null,
        admin_level_1_type:  p.admin_level_1_type || null,
        district:            p.district || null,
        poe_type:            (p.poe_type || 'land_border').toLowerCase(),
        transport_mode:      (p.transport_mode || 'land').toLowerCase(),
        regional_cluster:    p.regional_cluster_or_rpheoc || p.province || null,
        is_national_level:   Boolean(p.is_national_level),
        is_major_entry:      Boolean(p.is_major_entry),
        is_recommended_osbp: Boolean(p.is_recommended_osbp),
        border_country:      p.border_country || null,
        latitude:            p.latitude || null,
        longitude:           p.longitude || null,
        gazette_source:      p.source_url || (poeTree.metadata && poeTree.metadata.dataset_name) || null,
        payload:             p,
    });
}

// ---- Endemic countries flattened to disease,country pairs -------------
const endemicRows = [];
for (const diseaseCode of Object.keys(endemic)) {
    const list = endemic[diseaseCode];
    if (!Array.isArray(list)) continue;
    const seen = new Set();
    for (const cc of list) {
        if (!cc || seen.has(cc)) continue; // dedup (Diseases_intelligence already uses Set on read)
        seen.add(cc);
        endemicRows.push({
            disease_code:  diseaseCode,
            country_code:  cc,
            endemicity_level: 'ENDEMIC',
            source: 'Diseases_intelligence.js §ENDEMIC_COUNTRIES (WHO oracle)',
        });
    }
}

// ---- Engine-config rows (flatten the engine + supporting trees) -------
// Each top-level key on window.DISEASES (other than `diseases`) becomes
// one row in ref_engine_config, JSON-encoded.
const engineConfigRows = [];
for (const k of Object.keys(engineConfig)) {
    engineConfigRows.push({
        config_key:   `diseases.${k}`,
        description:  `window.DISEASES.${k} from src/Diseases.js`,
        config_value: engineConfig[k],
        version:      (engineConfig.metadata && engineConfig.metadata.version) || null,
        section:      k === 'engine' ? 'engine' : (k === 'metadata' ? 'metadata' : 'reference'),
    });
}

// Add the EXPOSURES.metadata + POE_MAIN.metadata so admin can see
// versioning info from a single config endpoint later.
if (exposureTree.metadata) {
    engineConfigRows.push({
        config_key:   'exposures.metadata',
        description:  'window.EXPOSURES.metadata from src/exposures.js',
        config_value: exposureTree.metadata,
        version:      exposureTree.metadata.version || null,
        section:      'metadata',
    });
}
if (poeTree.metadata) {
    engineConfigRows.push({
        config_key:   'poes.metadata',
        description:  'window.POE_MAIN.metadata from src/POEs.js',
        config_value: poeTree.metadata,
        version:      (poeTree.metadata.schema_version || null),
        section:      'metadata',
    });
}

// ---- Disease rows (flatten to ref_diseases shape) ---------------------
function ihrTier(d) {
    const tier = (d.priority_tier || '').toLowerCase();
    if (tier.includes('tier_1') || (d.who_category || '').includes('TIER1')) return 1;
    if (tier.includes('tier_2') || (d.who_category || '').includes('TIER2') || (d.who_category || '').includes('PRIORITY')) return 2;
    return 3;
}

const diseaseRows = diseases.map(d => ({
    disease_code:        d.id,
    display_name:        d.name || d.id,
    ihr_tier:            ihrTier(d),
    who_syndrome:        Array.isArray(d.syndromes) && d.syndromes.length ? d.syndromes[0] : null,
    incubation_days_min: d.incubation_days ? d.incubation_days.min : null,
    incubation_days_max: d.incubation_days ? d.incubation_days.max : null,
    case_definition:     {
        priority_tier: d.priority_tier,
        who_category:  d.who_category,
        severity:      d.severity,
        cfr_pct:       d.case_fatality_rate_pct,
        syndromes:     d.syndromes,
        hallmarks:     d.hallmarks,
        key_distinguishers: d.key_distinguishers,
        recommended_tests:  d.recommended_tests,
        immediate_actions:  d.immediate_actions,
        who_basis:          d.who_basis,
    },
    gates:               d.gates || null,
    symptom_weights:     d.symptom_weights || null,
    exposure_weights:    d.exposure_weights || null,
    triage_overrides:    d.triage_overrides || null,
    absent_penalties:    d.absent_hallmark_penalties || null,
    sources:             engineConfig.sources || null,
    payload:             d,
}));

// ---- Write JSON dumps -------------------------------------------------
function dump(name, data) {
    fs.writeFileSync(path.join(outDir, name), JSON.stringify(data, null, 2));
    console.log(`wrote ${name} — ${Array.isArray(data) ? data.length + ' rows' : 'object'}`);
}

dump('poes.json',                poeRows);
dump('diseases.json',            diseaseRows);
dump('symptoms.json',            symptomCatalogue);
dump('exposures.json',           exposureRows);
dump('exposure_mappings.json',   exposureMappings);
dump('engine_config.json',       engineConfigRows);
dump('endemic_countries.json',   endemicRows);

// ---- Manifest with row counts (useful for parity test in REF-6) -------
const manifest = {
    extracted_at: new Date().toISOString(),
    source_files: {
        'src/Diseases.js':              fs.statSync(path.join(srcDir, 'Diseases.js')).size,
        'src/Diseases_intelligence.js': fs.statSync(path.join(srcDir, 'Diseases_intelligence.js')).size,
        'src/exposures.js':             fs.statSync(path.join(srcDir, 'exposures.js')).size,
        'src/POEs.js':                  fs.statSync(path.join(srcDir, 'POEs.js')).size,
    },
    counts: {
        poes:                poeRows.length,
        diseases:            diseaseRows.length,
        symptoms:            symptomCatalogue.length,
        exposures:           exposureRows.length,
        exposure_mappings:   exposureMappings.length,
        engine_config:       engineConfigRows.length,
        endemic_countries:   endemicRows.length,
    },
    versions: {
        diseases:  engineConfig.metadata && engineConfig.metadata.version,
        exposures: exposureTree.metadata && exposureTree.metadata.version,
        poes:      poeTree.metadata && poeTree.metadata.schema_version,
    },
};
fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
console.log('manifest written');
console.log(JSON.stringify(manifest.counts, null, 2));
