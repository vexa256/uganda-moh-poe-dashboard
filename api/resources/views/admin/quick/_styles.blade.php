{{-- Shared Quick-Reports primitives. Included via @include in every QR blade
     under admin.quick.*. MUST be `text/tailwindcss` so the Tailwind Play CDN
     compiles @apply at runtime. Loaded inside @push('head'). --}}
<style type="text/tailwindcss">
    .qr-stack       { @apply space-y-4; }
    .qr-card        { @apply rounded-xl border border-border/70 bg-card text-card-foreground shadow-elevation-1; }
    .qr-card-head   { @apply flex items-center justify-between gap-3 px-4 py-3 border-b border-border/70; }
    .qr-card-title  { @apply text-[13px] font-semibold tracking-tight text-foreground; }
    .qr-card-sub    { @apply text-[11px] text-muted-foreground; }
    .qr-divider     { @apply border-t border-border/70; }
    .qr-card-pad    { @apply px-4 py-3; }

    .qr-kpi-grid    { @apply grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5; }
    .qr-kpi         { @apply rounded-lg border border-border/70 bg-card px-3.5 py-2.5 transition-colors duration-150; }
    .qr-kpi-label   { @apply text-[10px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
    .qr-kpi-value   { @apply mt-1 text-[22px] leading-none font-semibold tabular-nums text-foreground; }
    .qr-kpi-hint    { @apply mt-1.5 text-[10.5px] text-muted-foreground; }
    .qr-kpi-warn    { @apply ring-1 ring-critical/30 bg-critical/[.03]; }
    .qr-kpi-warn .qr-kpi-value  { @apply text-critical; }
    .qr-kpi-info .qr-kpi-value  { @apply text-brand-ink; }
    .qr-kpi-skel    { @apply rounded-lg border border-dashed border-border/60 px-3.5 py-3 h-[78px] animate-pulse bg-muted/20; }

    .qr-table-wrap  { @apply relative w-full overflow-auto max-h-[640px]; }
    .qr-table       { @apply w-full text-[12.5px] border-separate; border-spacing: 0; }
    .qr-table thead th {
        @apply text-[10px] font-semibold uppercase tracking-[.10em] text-muted-foreground
               bg-muted/50 backdrop-blur-sm
               px-3 py-2 text-left whitespace-nowrap
               border-b border-border/70 sticky top-0 z-10 select-none;
    }
    .qr-table tbody td { @apply px-3 py-1.5 border-b border-border/40 align-middle whitespace-nowrap; }
    .qr-table tbody tr { transition: background-color 120ms ease; }
    .qr-table tbody tr:hover td { @apply bg-muted/40; }
    .qr-table tbody tr:last-child td { @apply border-b-0; }
    .qr-cell-primary   { @apply font-medium text-foreground; }
    .qr-cell-secondary { @apply text-[10.5px] text-muted-foreground tabular-nums; }
    .qr-cell-mono      { @apply font-mono text-[11px] text-muted-foreground; }

    .qr-pill           { @apply inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap; }
    .qr-pill-low       { @apply bg-success/10 text-success ring-1 ring-success/30; }
    .qr-pill-med       { @apply bg-warning/15 text-warning ring-1 ring-warning/30; }
    .qr-pill-high      { @apply bg-critical/10 text-critical ring-1 ring-critical/35; }
    .qr-pill-crit      { @apply bg-critical text-critical-foreground; }
    .qr-pill-muted     { @apply bg-muted text-foreground/75 ring-1 ring-border/60; }
    .qr-pill-info      { @apply bg-info/10 text-info ring-1 ring-info/30; }
    .qr-pill-success   { @apply bg-success/12 text-success ring-1 ring-success/30; }

    .qr-icon-btn {
        @apply inline-flex h-7 w-7 items-center justify-center rounded-md
               text-[11px] font-semibold text-muted-foreground
               border border-transparent hover:bg-muted hover:text-foreground
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1
               transition-colors duration-150;
    }
    .qr-link-btn {
        @apply inline-flex items-center gap-1 rounded-md px-2 py-1
               text-[11.5px] font-semibold text-brand-ink
               border border-brand-soft bg-brand-soft hover:bg-brand-soft/70
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1;
    }
    .qr-empty {
        @apply rounded-lg border border-dashed border-border/70 bg-muted/20
               py-10 text-center text-muted-foreground text-[12.5px];
    }

    .qr-chart-wrap  { @apply relative w-full overflow-x-hidden overflow-y-auto; min-height: 280px; max-height: 540px; }
    .qr-chart-skel  { @apply rounded-lg bg-muted/25 animate-pulse; height: 320px; }

    .qr-progress    { @apply fixed inset-x-0 top-0 h-[2px] z-[60] overflow-hidden pointer-events-none; }
    .qr-progress::after { content: ''; @apply absolute inset-y-0 left-0 bg-brand; width: 35%; animation: qr-slide 1.1s ease-in-out infinite; }
    @keyframes qr-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(320%); } }
    @media (prefers-reduced-motion: reduce) {
        .qr-progress::after, .qr-kpi-skel, .qr-chart-skel { animation: none !important; }
    }

    .qr-modal-bg    { @apply fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm; }
    .qr-modal-shell { @apply fixed inset-0 z-[81] flex items-stretch justify-stretch p-0 sm:p-8 sm:items-center sm:justify-center; }
    .qr-modal       { @apply relative w-full sm:max-w-3xl max-h-[100dvh] sm:max-h-[88dvh] flex flex-col bg-card border border-border shadow-elevation-5 sm:rounded-xl overflow-hidden; }
    .qr-modal-head  { @apply flex items-center justify-between gap-3 px-5 py-3 border-b border-border/70; }
    .qr-modal-body  { @apply flex-1 overflow-auto px-5 py-4 text-[13px] leading-relaxed; }
    .qr-modal-foot  { @apply flex items-center justify-end gap-2 px-5 py-3 border-t border-border/70 bg-muted/30; }
    .qr-modal-section { @apply space-y-1; }
    .qr-modal-section p:first-child { @apply text-[11px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
</style>
