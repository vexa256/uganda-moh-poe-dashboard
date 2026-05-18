// Anchor coverage gate — every active disease's idsr_source_ref MUST
// resolve to an anchor in `.claude/context/IDSR_RWANDA_TABLE1.md`.
// This is a Phase-8 hardening check; the broader source-integrity test
// covers it but this file lets CI flag missing anchors directly.

import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { getEngine } from './_helpers/loadEngine.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..', '..');
const MANIFEST = path.join(repoRoot, '.claude/context/IDSR_RWANDA_TABLE1.md');

let D;
let anchors;

beforeAll(() => {
  D = getEngine();
  const md = fs.readFileSync(MANIFEST, 'utf8');
  anchors = new Set([...md.matchAll(/`(#[a-z0-9-]+)`/g)].map(m => m[1]));
});

describe('IDSR anchor coverage', () => {
  it('every active disease idsr_source_ref resolves in IDSR_RWANDA_TABLE1.md', () => {
    const missing = D.diseases
      .filter(d => d.idsr_source_ref)
      .filter(d => !anchors.has(d.idsr_source_ref))
      .map(d => ({ id: d.id, ref: d.idsr_source_ref }));
    expect(missing).toEqual([]);
  });

  it('every active non-event disease has an idsr_source_ref', () => {
    const orphans = D.diseases
      .filter(d => d.category !== 'event')
      .filter(d => !d.idsr_source_ref)
      .map(d => d.id);
    expect(orphans).toEqual([]);
  });

  it('legacy diseases have idsr_source_ref set to null', () => {
    const wronglyAnchored = D.legacy_diseases
      .filter(d => d.idsr_source_ref !== null)
      .map(d => d.id);
    expect(wronglyAnchored).toEqual([]);
  });
});
