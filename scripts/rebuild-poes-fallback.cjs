#!/usr/bin/env node
/**
 * scripts/rebuild-poes-fallback.cjs
 *
 * Regenerates the EMBEDDED_FALLBACK literal in src/POEs.js from the
 * canonical bundle source.  Precedence of sources:
 *
 *   1. --url <http://api-host/api/poes/bundle>   — live DB snapshot
 *   2. default: tests/fixtures/poe_main.golden.json
 *
 * Usage:
 *   node scripts/rebuild-poes-fallback.cjs
 *   node scripts/rebuild-poes-fallback.cjs --url http://127.0.0.1:8000/api/poes/bundle
 *
 * Exits non-zero if the rebuild would change the file's bundle shape in
 * a way that breaks the top-level contract (missing key / wrong type).
 */
const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const POES_JS   = path.join(REPO_ROOT, 'src/POEs.js')
const GOLDEN    = path.join(REPO_ROOT, 'tests/fixtures/poe_main.golden.json')

function parseArgs (argv) {
  const out = { url: null }
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--url' && argv[i + 1]) { out.url = argv[++i]; continue }
  }
  return out
}

function fetchBundle (url) {
  return new Promise((resolve, reject) => {
    const lib = url.startsWith('https') ? require('https') : require('http')
    lib.get(url, res => {
      if (res.statusCode !== 200) { reject(new Error('HTTP ' + res.statusCode)); return }
      let buf = ''
      res.on('data', c => buf += c)
      res.on('end', () => {
        try {
          const body = JSON.parse(buf)
          if (!body || !body.data) { reject(new Error('No data in response')); return }
          resolve(body.data)
        } catch (e) { reject(e) }
      })
    }).on('error', reject)
  })
}

function validateBundle (b) {
  const ok = b && typeof b === 'object'
    && b.metadata && typeof b.metadata === 'object'
    && b.traveler_notes && typeof b.traveler_notes === 'object'
    && Array.isArray(b.administrative_groups)
    && Array.isArray(b.poes)
  if (!ok) throw new Error('Bundle missing required top-level keys.')
  if (b.poes.length === 0) throw new Error('Bundle contains zero POEs — aborting to avoid clobbering fallback.')
}

async function main () {
  const args = parseArgs(process.argv)
  let bundle
  if (args.url) {
    console.log('> fetching live bundle from', args.url)
    bundle = await fetchBundle(args.url)
  } else {
    console.log('> using golden fixture', path.relative(REPO_ROOT, GOLDEN))
    bundle = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
  }
  validateBundle(bundle)

  const json = JSON.stringify(bundle, null, 2) + '\n'
  fs.writeFileSync(GOLDEN, json)
  console.log('> wrote', path.relative(REPO_ROOT, GOLDEN), '(' + json.length + ' bytes)')

  const jsLit = JSON.stringify(JSON.stringify(bundle))
  const src = fs.readFileSync(POES_JS, 'utf8')
  const next = src.replace(
    /const\s+EMBEDDED_FALLBACK\s*=\s*JSON\.parse\([\s\S]*?\)\s*\n/,
    'const EMBEDDED_FALLBACK = JSON.parse(' + jsLit + ')\n'
  )
  if (next === src) {
    throw new Error('Did not find EMBEDDED_FALLBACK assignment in src/POEs.js — check marker.')
  }
  fs.writeFileSync(POES_JS, next)
  console.log('> rewrote', path.relative(REPO_ROOT, POES_JS), '(' + next.length + ' bytes)')
  console.log('> poes=' + bundle.poes.length + ' groups=' + bundle.administrative_groups.length)
}

main().catch(e => { console.error('FAIL:', e.message); process.exit(1) })
