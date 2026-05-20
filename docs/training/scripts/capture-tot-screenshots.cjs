/* capture-tot-screenshots.cjs
 *
 * Signs into the Training dashboard and saves a clean PNG of every
 * surface used in the Train-the-Trainer deck. Run with:
 *
 *   cd /home/hacker/ecsa-uganda-poe
 *   node docs/training/scripts/capture-tot-screenshots.cjs
 *
 * Output: docs/training/screenshots/<slug>.png
 */
const puppeteer = require('puppeteer');
const path      = require('path');
const fs        = require('fs');

const BASE   = 'https://ug-poe.ecsahc.com';
const USER   = 'sarah.nakimuli';
const PASS   = 'Training@2026';
const OUT    = path.join(__dirname, '..', 'screenshots');
const VIEW   = { width: 1440, height: 900, deviceScaleFactor: 2 };

const SURFACES = [
  // [slug, path, optionalWaitSelector]
  ['00-screening-volume',  '/admin/quick-reports/screening-volume?days=7', null],
  ['01-suspected-cases',   '/admin/quick-reports/suspected-cases?days=7',  null],
  ['02-confirmed-cases',   '/admin/quick-reports/confirmed-cases?days=7',  null],
  ['03-alert-database',    '/admin/quick-reports/alert-database?days=7',   null],
  ['04-alert-analysis',    '/admin/quick-reports/alert-analysis?days=7',   null],
  ['05-alert-outcomes',    '/admin/quick-reports/alert-outcomes?days=7',   null],
  ['06-symptom-spread',    '/admin/quick-reports/symptom-spread?days=7',   null],
  ['07-poe-analysis',      '/admin/quick-reports/poe-analysis?days=7',     null],
  ['08-country-analysis',  '/admin/quick-reports/country-analysis?days=7', null],
  ['09-daily-screening',   '/admin/quick-reports/daily-screening?days=7',  null],
  ['10-user-analysis',     '/admin/quick-reports/user-analysis?days=7',    null],
  ['11-alerts-hub',        '/admin/alerts',                                null],
  ['12-workforce',         '/admin/workforce',                             null],
  ['13-geo-regions',       '/admin/geo/provinces',                         null],
  ['14-geo-districts',     '/admin/geo/districts',                         null],
  ['15-geo-hospitals',     '/admin/geo/hospitals',                         null],
  ['16-geo-countries',     '/admin/geo/countries',                         null],
  ['17-poe-registry',      '/admin/geo/poes',                              null],
  ['18-poe-capacity',      '/admin/poe/capacity',                          null],
  ['19-poe-status',        '/admin/poe/status',                            null],
  ['20-roster-ladder',     '/admin/poe/contacts',                          null],
];

function log(msg) { process.stdout.write(`\x1b[36m==> \x1b[0m${msg}\n`); }
function ok(msg)  { process.stdout.write(`\x1b[32m[OK]\x1b[0m ${msg}\n`); }
function bad(msg) { process.stdout.write(`\x1b[31m[FAIL]\x1b[0m ${msg}\n`); }

(async () => {
  if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

  log('Launching headless Chrome…');
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport(VIEW);

  log(`Sign-in as ${USER} at ${BASE}/login`);
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2', timeout: 60_000 });
  await page.type('input[name="identifier"]', USER);
  await page.type('input[name="password"]',   PASS);
  await Promise.all([
    page.click('button[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60_000 }),
  ]);

  const landingUrl = page.url();
  if (!landingUrl.includes('/admin/')) {
    bad(`Login did not redirect to /admin/ — currently at ${landingUrl}`);
    await browser.close();
    process.exit(1);
  }
  ok(`Signed in — landed on ${landingUrl}`);

  let pass = 0, fail = 0;
  for (const [slug, urlPath, waitSel] of SURFACES) {
    const url = `${BASE}${urlPath}`;
    const file = path.join(OUT, `${slug}.png`);
    try {
      log(`[${slug}] ${urlPath}`);
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 60_000 });
      // Give charts/tables a beat to finish rendering after networkidle.
      await new Promise(r => setTimeout(r, 1800));
      if (waitSel) await page.waitForSelector(waitSel, { timeout: 8_000 }).catch(() => null);
      await page.screenshot({ path: file, fullPage: true });
      const sz = (fs.statSync(file).size / 1024).toFixed(0);
      ok(`${slug} → ${sz} KB`);
      pass++;
    } catch (e) {
      bad(`${slug}: ${e.message}`);
      // Try to save whatever rendered so we have evidence.
      try { await page.screenshot({ path: file, fullPage: true }); } catch {}
      fail++;
    }
  }

  await browser.close();
  log(`Done — ${pass} OK, ${fail} failed.`);
  process.exit(fail ? 2 : 0);
})();
