<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? 'Uganda POE Sentinel · Alert' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .surface { background:#F8FAFC; }
        .panel   { background:#FFFFFF; border:1px solid #E2E8F0; border-radius:14px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .pill    { display:inline-flex; align-items:center; gap:.4rem; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
        .pill-critical { background:#FEE2E2; color:#B91C1C; }
        .pill-high     { background:#FFEDD5; color:#C2410C; }
        .pill-medium   { background:#FEF3C7; color:#B45309; }
        .pill-low      { background:#DCFCE7; color:#047857; }
        .label   { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748B; font-weight:600; }
        .value   { font-size:14px; color:#0F172A; }
        .btn     { display:inline-flex; align-items:center; gap:.5rem; padding:10px 18px; border-radius:10px; font-weight:600; font-size:14px; line-height:1; transition:background .12s; }
        .btn-primary { background:#0F172A; color:#FFFFFF; }
        .btn-primary:hover { background:#1E293B; }
        .btn-ghost   { background:transparent; color:#0F172A; border:1px solid #CBD5E1; }
        .btn-ghost:hover { background:#F1F5F9; }
    </style>
</head>
<body class="surface min-h-screen">
    <header class="bg-slate-900 text-white">
        <div class="max-w-3xl mx-auto px-5 py-4 flex items-center justify-between">
            <div class="font-bold text-[14px] tracking-wider">UGANDA POE SENTINEL</div>
            <div class="text-[11px] uppercase tracking-wider text-slate-300">Alert workspace · guest access</div>
        </div>
    </header>
    <main class="max-w-3xl mx-auto px-4 py-8">
        @yield('body')
    </main>
    <footer class="max-w-3xl mx-auto px-4 pt-2 pb-10 text-center text-[11px] text-slate-500">
        This page was opened with a single-use signed link issued by Uganda POE Sentinel.<br>
        Do not forward — the link can be opened only once. Need a fresh link? Contact the alert sender.
    </footer>
</body>
</html>
