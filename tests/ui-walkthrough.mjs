// tests/ui-walkthrough.mjs
//
// Aggressive user-simulating UI walk-through. Visits every route in the
// app via a headless Android-sized viewport, captures a full-page
// screenshot, and logs any console errors / page errors / unhandled
// rejections / failed requests that surface.
//
// Assumes a vite preview (or any static server) is already running at
// http://127.0.0.1:4173. To start it:
//
//   npx vite preview --host 127.0.0.1 --port 4173
//
// Or pass BASE_URL=http://host:port node tests/ui-walkthrough.mjs
//
// Outputs:
//   tests/screenshots/<route>.png
//   tests/ui-walkthrough-report.json

import puppeteer from 'puppeteer'
import { mkdir, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4173'
const OUT_DIR = resolve(__dirname, 'screenshots')
const REPORT_PATH = resolve(__dirname, 'ui-walkthrough-report.json')

// Every route declared in src/router/index.js (31 entries). With hash
// history the actual URL form is `/#/path`. Root redirects collapse.
const ROUTES = [
  { name: 'home',                    path: '/' },
  { name: 'dashboard-redirect',      path: '/dashboard' },
  { name: 'home-explicit',           path: '/home' },
  { name: 'disease-intelligence',    path: '/DiseaseInteligence' },
  { name: 'primary-screening',       path: '/PrimaryScreening' },
  { name: 'screening-dashboard',     path: '/screening-dashboard' },
  { name: 'primary-screening-dashboard-legacy', path: '/primary-screening/dashboard' },
  { name: 'primary-screening-records', path: '/primary-screening/records' },
  { name: 'notifications-center',    path: '/NotificationsCenter' },
  { name: 'secondary-records',       path: '/secondary-screening/records' },
  { name: 'secondary-screening-one', path: '/secondary-screening/nonexistent-uuid' },
  { name: 'alerts-history',          path: '/alerts/history' },
  { name: 'alerts-intelligence',     path: '/alerts/intelligence' },
  { name: 'alerts-matrix',           path: '/alerts/matrix' },
  { name: 'alerts',                  path: '/alerts' },
  { name: 'admin-aggregated-templates', path: '/admin/aggregated-templates' },
  { name: 'admin-aggregated-wizard',    path: '/admin/aggregated-wizard' },
  { name: 'admin-poe-contacts',         path: '/admin/poe-contacts' },
  { name: 'aggregated-data',         path: '/aggregated-data' },
  { name: 'aggregated-history',      path: '/aggregated-data/history' },
  { name: 'aggregated-new',          path: '/aggregated-data/new/1' },
  { name: 'aggregated-new-noid',     path: '/aggregated-data/new' },
  { name: 'aggregated-legacy',       path: '/aggregated' },
  { name: 'sync-queue',              path: '/sync/queue' },
  { name: 'sync-history',            path: '/sync/history' },
  { name: 'sync-failed',             path: '/sync/failed' },
  { name: 'sync',                    path: '/sync' },
  { name: 'admin-system-redirect',   path: '/admin/system' },
  { name: 'poes',                    path: '/POEs' },
  { name: 'users',                   path: '/Users' },
  { name: 'profile',                 path: '/profile' },
  { name: 'settings',                path: '/settings' },
  { name: 'not-found-catch',         path: '/some/route/that/does/not/exist' },
]

async function main() {
  if (!existsSync(OUT_DIR)) await mkdir(OUT_DIR, { recursive: true })

  const browser = await puppeteer.launch({
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
    ],
  })

  const report = {
    baseUrl: BASE,
    startedAt: new Date().toISOString(),
    totalRoutes: ROUTES.length,
    passed: 0,
    failed: 0,
    warned: 0,
    routes: [],
  }

  for (const route of ROUTES) {
    const hashUrl = `${BASE}/#${route.path.startsWith('/') ? route.path : '/' + route.path}`
    const entry = {
      name: route.name,
      path: route.path,
      url: hashUrl,
      status: 'pass',
      consoleErrors: [],
      pageErrors: [],
      failedRequests: [],
      loadMs: null,
      screenshot: null,
    }

    const page = await browser.newPage()

    // Pixel-6-ish Android viewport (Google Play review rig reference)
    await page.setViewport({
      width: 412, height: 915,
      deviceScaleFactor: 2,
      isMobile: true,
      hasTouch: true,
    })
    await page.setUserAgent(
      'Mozilla/5.0 (Linux; Android 14; Pixel 6) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'
    )

    // Pre-seed the auth-data sessionStorage entry so App.vue's login
    // guard does not cover every route with its login overlay. This is
    // read-only — no business logic is patched.
    await page.evaluateOnNewDocument(() => {
      const now = new Date().toISOString()
      const AUTH = {
        id: 1,
        role_key: 'NATIONAL_ADMIN',
        country_code: 'UG',
        full_name: 'UI Walkthrough Tester',
        username: 'ui.tester',
        email: 'ui.tester@example.org',
        phone: '',
        is_active: true,
        last_login_at: now,
        created_at: now,
        updated_at: now,
        email_verified_at: now,
        name: 'UI Walkthrough Tester',
        _logged_in_at: now,
        _offline_login: false,
        _permissions: {
          view_dashboard: true, view_alerts: true, manage_alerts: true,
          view_primary_screening: true, capture_primary_screening: true,
          view_secondary_screening: true, capture_secondary_screening: true,
          view_users: true, manage_users: true, view_poes: true,
          manage_poes: true, view_settings: true, manage_settings: true,
          view_aggregated: true, manage_aggregated: true, view_sync: true,
          view_notifications: true, view_intelligence: true, view_matrix: true,
          view_history: true, view_profile: true, view_disease_intelligence: true,
        },
      }
      try { sessionStorage.setItem('AUTH_DATA', JSON.stringify(AUTH)) } catch {}
    })

    page.on('console', (msg) => {
      if (msg.type() === 'error') entry.consoleErrors.push(msg.text())
    })
    page.on('pageerror', (err) => {
      entry.pageErrors.push(String(err && err.message || err))
    })
    page.on('requestfailed', (req) => {
      const url = req.url()
      // Ignore any API call failures — we're not running the Laravel backend
      // in this test rig, and every view is offline-first.
      if (/\/api\b|:8000\b|\.php\b/.test(url)) return
      entry.failedRequests.push({
        url, errorText: req.failure()?.errorText || 'unknown',
      })
    })

    const t0 = Date.now()
    try {
      await page.goto(hashUrl, { waitUntil: 'networkidle0', timeout: 30000 })
      // Give Ionic mount + route animation a beat
      await new Promise(r => setTimeout(r, 900))
      entry.loadMs = Date.now() - t0
      const shotPath = resolve(OUT_DIR, `${route.name}.png`)
      await page.screenshot({ path: shotPath, fullPage: true })
      entry.screenshot = `screenshots/${route.name}.png`
    } catch (err) {
      entry.status = 'fail'
      entry.pageErrors.push(`goto failed: ${err.message}`)
    } finally {
      await page.close()
    }

    // Severity: any pageerror/consoleerror/failed-non-api-request = warn
    if (entry.pageErrors.length > 0) entry.status = 'fail'
    else if (entry.consoleErrors.length + entry.failedRequests.length > 0) entry.status = 'warn'

    if (entry.status === 'pass') report.passed++
    else if (entry.status === 'warn') report.warned++
    else report.failed++

    report.routes.push(entry)
    const flag = entry.status === 'pass' ? '✓' :
                 entry.status === 'warn' ? '⚠' : '✗'
    const errHint = entry.pageErrors[0] || entry.consoleErrors[0] || ''
    console.log(
      `${flag} ${route.name.padEnd(40)} ${String(entry.loadMs ?? '—').padStart(5)}ms` +
      (errHint ? `  — ${errHint.slice(0, 100)}` : '')
    )
  }

  report.finishedAt = new Date().toISOString()
  await writeFile(REPORT_PATH, JSON.stringify(report, null, 2))

  console.log(
    `\nTotals: ${report.passed} pass, ${report.warned} warn, ${report.failed} fail, ` +
    `${report.totalRoutes} total`
  )
  console.log(`Report:      ${REPORT_PATH}`)
  console.log(`Screenshots: ${OUT_DIR}/`)

  await browser.close()
  process.exit(report.failed > 0 ? 1 : 0)
}

main().catch((e) => {
  console.error('fatal', e)
  process.exit(2)
})
