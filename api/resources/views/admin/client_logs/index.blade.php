@extends('admin.layout')

@section('eyebrow', 'Telemetry')
@section('title', 'Client Error Logs')
@section('subtitle', 'Realtime stream of every error captured from the mobile + web app.')

@push('head')
<style>
  .cl-wrap { display: flex; flex-direction: column; gap: 16px; padding: 16px 24px; }
  .cl-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
  .cl-stat {
    background: #fff; border: 1px solid #E2E8F0; border-radius: 12px;
    padding: 14px; text-align: center;
  }
  .cl-stat-num { font-size: 22px; font-weight: 800; color: #0F172A; }
  .cl-stat-lbl { font-size: 10px; font-weight: 700; color: #64748B; letter-spacing: .8px; text-transform: uppercase; margin-top: 4px; }
  .cl-stat--err { border-color: #FCA5A5; background: linear-gradient(180deg, #FEF2F2, #fff); }
  .cl-stat--err .cl-stat-num { color: #B91C1C; }
  .cl-stat--warn { border-color: #FCD34D; background: linear-gradient(180deg, #FFFBEB, #fff); }
  .cl-stat--warn .cl-stat-num { color: #B45309; }
  .cl-stat--info { border-color: #93C5FD; background: linear-gradient(180deg, #EFF6FF, #fff); }
  .cl-stat--info .cl-stat-num { color: #1D4ED8; }

  .cl-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    padding: 12px 14px; background: #fff; border: 1px solid #E2E8F0; border-radius: 12px;
  }
  .cl-bar input, .cl-bar select {
    padding: 8px 12px; border: 1px solid #CBD5E1; border-radius: 8px; font: inherit;
  }
  .cl-bar input { min-width: 240px; }
  .cl-bar .cl-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 999px; font-size: 11px; font-weight: 700;
    background: #F1F5F9; color: #475569; cursor: pointer; user-select: none;
    border: 1.5px solid transparent;
  }
  .cl-pill--active { background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD; }
  .cl-pill--paused { background: #FEE2E2; color: #B91C1C; border-color: #FCA5A5; }
  .cl-bar .cl-pause { margin-left: auto; }

  .cl-list {
    background: #fff; border: 1px solid #E2E8F0; border-radius: 12px;
    overflow: hidden; max-height: 65vh; overflow-y: auto;
  }
  .cl-row {
    display: grid;
    grid-template-columns: 80px 70px 1fr 200px 120px;
    gap: 12px; padding: 10px 14px;
    border-bottom: 1px solid #F1F5F9;
    cursor: pointer; align-items: center;
    transition: background .12s;
  }
  .cl-row:hover { background: #F8FAFC; }
  .cl-row--new { animation: cl-flash 1.4s ease-out; }
  @keyframes cl-flash {
    0%   { background: #FEF3C7; }
    100% { background: #fff; }
  }
  .cl-lvl {
    font-size: 9px; font-weight: 800; letter-spacing: .8px;
    padding: 3px 7px; border-radius: 4px; text-align: center;
  }
  .cl-lvl--ERROR { background: #B91C1C; color: #fff; }
  .cl-lvl--FATAL { background: #7F1D1D; color: #fff; }
  .cl-lvl--WARN  { background: #F59E0B; color: #fff; }
  .cl-lvl--INFO  { background: #3B82F6; color: #fff; }
  .cl-lvl--DEBUG { background: #94A3B8; color: #fff; }
  .cl-lvl--TRACE { background: #CBD5E1; color: #334155; }

  .cl-time { font-size: 10px; color: #64748B; font-variant-numeric: tabular-nums; }
  .cl-msg  { font-size: 12.5px; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cl-meta { font-size: 10.5px; color: #64748B; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cl-route { font-size: 10px; color: #475569; font-family: ui-monospace, monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  .cl-empty {
    text-align: center; padding: 40px 20px; color: #64748B; font-size: 13px;
  }

  /* Detail panel */
  .cl-detail-overlay {
    position: fixed; inset: 0; background: rgba(15,23,42,.45);
    display: flex; justify-content: flex-end; z-index: 1000;
  }
  .cl-detail {
    width: min(560px, 100vw); height: 100%;
    background: #fff; box-shadow: -4px 0 24px rgba(0,0,0,.15);
    overflow-y: auto; display: flex; flex-direction: column;
  }
  .cl-detail-hdr {
    position: sticky; top: 0; z-index: 1; background: #fff;
    padding: 16px 18px; border-bottom: 1px solid #E2E8F0;
    display: flex; align-items: center; gap: 12px;
  }
  .cl-detail-close {
    width: 32px; height: 32px; border-radius: 8px;
    border: 1px solid #E2E8F0; background: #fff; cursor: pointer;
    font-size: 18px; line-height: 1;
  }
  .cl-detail-body { padding: 16px 18px; }
  .cl-section { margin-bottom: 18px; }
  .cl-section h4 {
    font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
    color: #64748B; font-weight: 800; margin: 0 0 6px;
  }
  .cl-section pre {
    background: #0F172A; color: #E2E8F0; padding: 12px; border-radius: 8px;
    font-size: 11px; line-height: 1.5; overflow: auto;
    max-height: 240px; white-space: pre-wrap; word-break: break-word;
  }
  .cl-section .cl-kv {
    display: grid; grid-template-columns: 130px 1fr; gap: 8px 12px;
    font-size: 12px;
  }
  .cl-section .cl-k { color: #64748B; font-weight: 600; }
  .cl-section .cl-v { color: #0F172A; word-break: break-all; font-family: ui-monospace, monospace; font-size: 11px; }
  .cl-bcrumb {
    background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px;
    padding: 6px 10px; margin-bottom: 4px; font-size: 11px;
  }
  .cl-bcrumb-cat { display: inline-block; background: #DBEAFE; color: #1D4ED8; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 700; margin-right: 6px; }

  .cl-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 6px;
    background: #F8FAFC; border: 1px solid #E2E8F0;
    font-size: 11px; font-weight: 600; cursor: pointer;
  }
  .cl-toggle--on { background: #DCFCE7; border-color: #86EFAC; color: #166534; }
</style>
@endpush

@section('content')
<div class="cl-wrap" id="cl-app">

  <!-- Stats tiles -->
  <div class="cl-stats" id="cl-stats">
    <div class="cl-stat cl-stat--err"><div class="cl-stat-num" data-stat="ERROR">0</div><div class="cl-stat-lbl">Errors · 24h</div></div>
    <div class="cl-stat cl-stat--err"><div class="cl-stat-num" data-stat="FATAL">0</div><div class="cl-stat-lbl">Fatal · 24h</div></div>
    <div class="cl-stat cl-stat--warn"><div class="cl-stat-num" data-stat="WARN">0</div><div class="cl-stat-lbl">Warnings · 24h</div></div>
    <div class="cl-stat cl-stat--info"><div class="cl-stat-num" data-stat="INFO">0</div><div class="cl-stat-lbl">Info · 24h</div></div>
    <div class="cl-stat"><div class="cl-stat-num" id="cl-stat-1h">0</div><div class="cl-stat-lbl">Total · 1h</div></div>
    <div class="cl-stat"><div class="cl-stat-num" id="cl-stat-latest">—</div><div class="cl-stat-lbl">Latest received</div></div>
  </div>

  <!-- Filter bar -->
  <div class="cl-bar">
    <input type="text" id="cl-q" placeholder="Search message / stack / route…" />
    <span class="cl-pill cl-pill--active" data-level="">All</span>
    <span class="cl-pill" data-level="ERROR">Error</span>
    <span class="cl-pill" data-level="FATAL">Fatal</span>
    <span class="cl-pill" data-level="WARN">Warn</span>
    <span class="cl-pill" data-level="INFO">Info</span>
    <span class="cl-pill" data-level="DEBUG">Debug</span>
    <span class="cl-pill cl-pause" id="cl-pause" title="Pause / resume realtime stream">⏸ Pause</span>
  </div>

  <!-- Live list -->
  <div class="cl-list" id="cl-list">
    <div class="cl-empty" id="cl-empty">Waiting for events…</div>
  </div>

</div>

<!-- Detail panel (hidden by default) -->
<div class="cl-detail-overlay" id="cl-detail-overlay" style="display:none;" onclick="if(event.target===this)closeDetail()">
  <div class="cl-detail" id="cl-detail-panel">
    <div class="cl-detail-hdr">
      <button class="cl-detail-close" onclick="closeDetail()" aria-label="Close">×</button>
      <strong id="cl-detail-title">Error detail</strong>
    </div>
    <div class="cl-detail-body" id="cl-detail-body">Loading…</div>
  </div>
</div>

<script>
(function () {
  const API = '/api/client-logs';
  const list = document.getElementById('cl-list');
  const empty = document.getElementById('cl-empty');
  const qEl = document.getElementById('cl-q');
  const pauseEl = document.getElementById('cl-pause');

  let cursor = 0;
  let levelFilter = '';
  let qFilter = '';
  let paused = false;
  let renderedIds = new Set();
  let allRecords = [];   // last ~500 records cached for client-side filter

  function levelClass(l) { return 'cl-lvl cl-lvl--' + (l || 'INFO'); }

  function fmtTime(s) {
    if (!s) return '—';
    try {
      const d = new Date(s.replace(' ', 'T') + 'Z');
      return d.toLocaleTimeString('en-GB');
    } catch { return s; }
  }

  function rowHtml(r) {
    const time = fmtTime(r.received_at || r.occurred_at);
    const meta = [r.role_key, r.platform, r.app_version].filter(Boolean).join(' · ');
    const msg = (r.message || '').replace(/[<>&]/g, ch => ({ '<':'&lt;', '>':'&gt;', '&':'&amp;' }[ch]));
    const route = (r.route || '').replace(/[<>&]/g, ch => ({ '<':'&lt;', '>':'&gt;', '&':'&amp;' }[ch]));
    return `<div class="cl-row cl-row--new" data-id="${r.id}" onclick="openDetail(${r.id})">
      <span class="${levelClass(r.level)}">${r.level}</span>
      <span class="cl-time">${time}</span>
      <span class="cl-msg" title="${msg}">${msg}</span>
      <span class="cl-meta" title="${meta}">${meta || '—'}</span>
      <span class="cl-route" title="${route}">${route || '—'}</span>
    </div>`;
  }

  function passesFilter(r) {
    if (levelFilter && r.level !== levelFilter) return false;
    if (qFilter) {
      const hay = (r.message + ' ' + (r.error_name || '') + ' ' + (r.route || '') + ' ' + (r.source || '')).toLowerCase();
      if (!hay.includes(qFilter.toLowerCase())) return false;
    }
    return true;
  }

  function rerender() {
    const visible = allRecords.filter(passesFilter);
    if (visible.length === 0) {
      list.innerHTML = '<div class="cl-empty">No matching events.</div>';
      return;
    }
    list.innerHTML = visible.map(rowHtml).join('');
  }

  function appendRecords(records) {
    if (!records || !records.length) return;
    // Maintain a 500-deep cache; always insert at front (newest first).
    for (const r of records.reverse()) {
      if (renderedIds.has(r.id)) continue;
      renderedIds.add(r.id);
      allRecords.unshift(r);
      if (r.id > cursor) cursor = r.id;
    }
    if (allRecords.length > 500) allRecords = allRecords.slice(0, 500);
    if (empty && empty.parentNode) empty.remove();
    rerender();
  }

  // Initial paint — last 50 records.
  fetch(API + '?per_page=50')
    .then(r => r.json())
    .then(d => {
      const records = d?.data?.records || [];
      // index returns newest first; we want appendRecords to reverse so
      // the unshift loop ends up newest-first.
      appendRecords(records.slice().reverse());
      if (records.length) cursor = Math.max(...records.map(x => x.id));
    })
    .catch(() => {});

  // Stats tiles + 1h/latest counter — refresh every 10 s.
  function refreshStats() {
    fetch(API + '/stats').then(r => r.json()).then(d => {
      const stats = d?.data || {};
      const byLvl = stats.last_24h_by_level || {};
      document.querySelectorAll('[data-stat]').forEach(el => {
        el.textContent = byLvl[el.dataset.stat] || 0;
      });
      document.getElementById('cl-stat-1h').textContent = stats.last_hour_total || 0;
      document.getElementById('cl-stat-latest').textContent = stats.latest_received
        ? fmtTime(stats.latest_received) : '—';
    }).catch(() => {});
  }
  refreshStats();
  setInterval(refreshStats, 10_000);

  // Realtime tail loop — long-poll the /stream cursor endpoint.
  async function tail() {
    while (true) {
      if (paused) { await sleep(500); continue; }
      try {
        const url = API + '/stream?after=' + cursor + (levelFilter ? '&level=' + levelFilter : '');
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) { await sleep(2000); continue; }
        const d = await res.json();
        const records = d?.data?.records || [];
        if (records.length) appendRecords(records);
      } catch {
        await sleep(2000);
      }
    }
  }
  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
  tail();

  // Filter pills
  document.querySelectorAll('.cl-pill[data-level]').forEach(pill => {
    pill.addEventListener('click', () => {
      document.querySelectorAll('.cl-pill[data-level]').forEach(p => p.classList.remove('cl-pill--active'));
      pill.classList.add('cl-pill--active');
      levelFilter = pill.dataset.level || '';
      rerender();
    });
  });

  // Search
  let qDebounce = null;
  qEl.addEventListener('input', () => {
    clearTimeout(qDebounce);
    qDebounce = setTimeout(() => {
      qFilter = qEl.value.trim();
      rerender();
    }, 200);
  });

  // Pause toggle
  pauseEl.addEventListener('click', () => {
    paused = !paused;
    pauseEl.textContent = paused ? '▶ Resume' : '⏸ Pause';
    pauseEl.classList.toggle('cl-pill--paused', paused);
  });

  // Detail panel
  window.openDetail = function (id) {
    document.getElementById('cl-detail-overlay').style.display = 'flex';
    const body = document.getElementById('cl-detail-body');
    body.innerHTML = 'Loading…';
    fetch(API + '/' + id)
      .then(r => r.json())
      .then(d => {
        const rec = d?.data?.record;
        if (!rec) { body.innerHTML = 'Not found.'; return; }
        document.getElementById('cl-detail-title').textContent =
          (rec.level || 'EVENT') + ' · #' + rec.id;
        body.innerHTML = renderDetail(rec);
      });
  };
  window.closeDetail = function () {
    document.getElementById('cl-detail-overlay').style.display = 'none';
  };

  function escape(s) {
    if (s == null) return '—';
    return String(s).replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function renderDetail(rec) {
    const kv = (k, v) => `<div class="cl-k">${k}</div><div class="cl-v">${escape(v)}</div>`;
    const meta =
      kv('Level',         rec.level) +
      kv('Source',        rec.source) +
      kv('URL',           rec.url) +
      kv('Route',         rec.route) +
      kv('User ID',       rec.user_id) +
      kv('Role',          rec.role_key) +
      kv('POE',           rec.poe_code) +
      kv('Device',        rec.device_id) +
      kv('Session',       rec.session_id) +
      kv('App version',   rec.app_version) +
      kv('Platform',      rec.platform) +
      kv('Online',        rec.online == null ? '—' : (rec.online ? 'yes' : 'no')) +
      kv('User agent',    rec.user_agent) +
      kv('Occurred at',   rec.occurred_at) +
      kv('Received at',   rec.received_at);

    const breadHtml = (rec.breadcrumbs || []).map(b => {
      const t = (b.t || '').slice(11, 19);
      return `<div class="cl-bcrumb">
        <span class="cl-bcrumb-cat">${escape(b.category)}</span>
        <span style="color:#64748B">${t}</span>
        ${escape(b.message)}
      </div>`;
    }).join('') || '<div class="cl-empty" style="padding:8px">No breadcrumbs.</div>';

    return `
      <div class="cl-section">
        <h4>Message</h4>
        <pre>${escape(rec.message)}</pre>
      </div>
      ${rec.stack ? `<div class="cl-section"><h4>Stack</h4><pre>${escape(rec.stack)}</pre></div>` : ''}
      ${rec.error_name || rec.error_message ? `<div class="cl-section"><h4>Error</h4>
        <pre>${escape((rec.error_name || '') + (rec.error_message ? ': ' + rec.error_message : ''))}</pre>
      </div>` : ''}
      <div class="cl-section">
        <h4>Context</h4>
        <div class="cl-kv">${meta}</div>
      </div>
      <div class="cl-section">
        <h4>Breadcrumbs (${(rec.breadcrumbs || []).length})</h4>
        ${breadHtml}
      </div>
      ${rec.extra ? `<div class="cl-section"><h4>Extra</h4>
        <pre>${escape(typeof rec.extra === 'string' ? rec.extra : JSON.stringify(rec.extra, null, 2))}</pre>
      </div>` : ''}
    `;
  }
})();
</script>
@endsection
