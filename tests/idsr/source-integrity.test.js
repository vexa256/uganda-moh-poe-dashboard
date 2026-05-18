// Paranoid enforcement of invariant I-13 — `RWANDA CASE FEFINITION.TXT`
// is the engine's frozen source-of-truth.
//
// This test runs at every phase boundary. It MUST be green before any
// phase is marked complete in 15-CHECKLIST.md.
//
// Scope grows with each phase:
//   Phase 0  → hash lock + companion files exist
//   Phase 1+ → every active disease has idsr_source_ref resolving to an anchor
//              in IDSR_RWANDA_TABLE1.md, and case_definition.suspected text
//              appears (whitespace-normalised) inside the source.
//
// If you're tempted to weaken any of these checks to make a phase pass —
// don't. Rollback the phase, fix the engine, re-run.

import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');

const SOURCE_PATH = path.join(repoRoot, 'RWANDA CASE FEFINITION.TXT');
const SHA_PATH    = path.join(repoRoot, '.claude/context/IDSR_RWANDA_TABLE1.sha256');
const MD_PATH     = path.join(repoRoot, '.claude/context/IDSR_RWANDA_TABLE1.md');

let sourceText = '';
let sourceTextNorm = '';
let manifestText = '';
let pinnedHash = '';
let computedHash = '';

beforeAll(() => {
  sourceText = fs.readFileSync(SOURCE_PATH, 'utf8');
  sourceTextNorm = sourceText.replace(/\s+/g, ' ').trim().toLowerCase();
  manifestText = fs.readFileSync(MD_PATH, 'utf8');
  pinnedHash = fs.readFileSync(SHA_PATH, 'utf8').trim().split(/\s+/)[0];
  computedHash = crypto.createHash('sha256').update(sourceText, 'utf8').digest('hex');
});

describe('I-13 source-of-truth lock', () => {
  it('source file exists at repo root', () => {
    expect(fs.existsSync(SOURCE_PATH)).toBe(true);
  });

  it('pinned sha256 file exists', () => {
    expect(fs.existsSync(SHA_PATH)).toBe(true);
  });

  it('IDSR manifest .md exists', () => {
    expect(fs.existsSync(MD_PATH)).toBe(true);
  });

  it('source sha256 matches pinned value', () => {
    expect(computedHash).toBe(pinnedHash);
  });

  it('manifest references the pinned sha256', () => {
    expect(manifestText).toContain(pinnedHash);
  });

  it('source contains canonical IDSR-Annex-1A markers', () => {
    expect(sourceText).toMatch(/Annex 1A/);
    expect(sourceText).toMatch(/Annex 1B/);
    expect(sourceText).toMatch(/Standard case definition/i);
  });
});

describe('I-13 active-disease source-anchor enforcement (skips until Phase 1 lands the field)', () => {
  let DISEASES;
  let activeDiseases;
  let anchorsInManifest;

  beforeAll(async () => {
    await import('./_baseline/bootstrap-window.mjs');
    DISEASES = globalThis.window.DISEASES;
    activeDiseases = (DISEASES?.diseases || []).filter((d) => d && d.id);
    anchorsInManifest = new Set(
      [...manifestText.matchAll(/`(#[a-z0-9-]+)`/g)].map((m) => m[1])
    );
  });

  it('engine loaded with at least 30 active diseases', () => {
    expect(activeDiseases.length).toBeGreaterThanOrEqual(30);
  });

  it('every active disease has idsr_source_ref OR phase-1 has not landed yet', () => {
    const missing = activeDiseases.filter((d) => !d.idsr_source_ref);
    if (missing.length === activeDiseases.length) {
      // Pre-Phase-1: field hasn't been added to anything yet. Skip silently.
      return;
    }
    // Once at least one disease has the field, EVERY active one must have it.
    expect(missing.map((d) => d.id)).toEqual([]);
  });

  it('every idsr_source_ref resolves to an anchor in IDSR_RWANDA_TABLE1.md', () => {
    const refs = activeDiseases.map((d) => d.idsr_source_ref).filter(Boolean);
    if (refs.length === 0) return; // pre-Phase-1
    const unresolved = refs.filter((ref) => !anchorsInManifest.has(ref));
    expect(unresolved).toEqual([]);
  });

  it('every active disease case_definition.suspected text appears in source (when set)', () => {
    const orphaned = [];
    for (const d of activeDiseases) {
      const susp = d.case_definition?.suspected;
      if (!susp || typeof susp !== 'string') continue;
      const norm = susp.replace(/\s+/g, ' ').trim().toLowerCase();
      // Allow short snippets to pass — only enforce for clauses ≥ 24 chars.
      if (norm.length < 24) continue;
      if (!sourceTextNorm.includes(norm)) {
        orphaned.push({ id: d.id, snippet: norm.slice(0, 80) });
      }
    }
    expect(orphaned).toEqual([]);
  });
});

describe('I-13 legacy diseases NEVER anchor to source', () => {
  let DISEASES;

  beforeAll(async () => {
    await import('./_baseline/bootstrap-window.mjs');
    DISEASES = globalThis.window.DISEASES;
  });

  it('every legacy disease has idsr_source_ref null OR legacy_diseases not yet introduced', () => {
    const legacy = DISEASES?.legacy_diseases || [];
    if (legacy.length === 0) return; // pre-Phase-1
    const wronglyAnchored = legacy.filter((d) => d && d.idsr_source_ref);
    expect(wronglyAnchored.map((d) => d.id)).toEqual([]);
  });
});
