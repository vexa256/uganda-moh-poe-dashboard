{{-- ============================================================================
  PHEOC COMMAND CENTRE · THEME FOUNDATION
  ----------------------------------------------------------------------------
  Light-only, shadcn-identical chassis with a disciplined semantic palette
  layered on top. This file is the ONE source of truth for:

    · Tailwind CDN config (colour tokens, fonts, radii, shadows, container)
    · Base styles (body, focus, reduced-motion, scrollbar, safe-area)
    · shadcn primitives (btn, input, card, badge, tabs, dialog, sheet,
      table, toast, alert, skeleton, label, separator, kbd, dropdown,
      command, progress, switch, popover)
    · Semantic tone tokens — risk (low / medium / high / critical),
      status (info / success / warning / danger), IHR tier accents,
      disease-family accents, case-progress sequence
    · Premium chrome (sidebar rail, topbar, copilot dock,
      KPI tile, ring gauge, sparkline)

  Status-board primitive directive (memorised 2026-04-23)
  ------------------------------------------------------------
    · NEVER kanban columns. Status surfaces use slim premium
      tables (.table-wrap / .table / .table-row) with tabs or
      filter chips, or card grids (.card). The .kanban-col /
      .kanban-card primitives have been removed from this theme
      to enforce the rule at the design-system layer.

  DESIGN DIRECTIVES ENFORCED HERE
  ------------------------------------------------------------
    · NEVER dark mode — only a light canvas. The .dark class is a no-op.
    · Neutral zinc canvas + emerald primary (public-health trust).
    · Colour is always semantic, never decorative.
    · Mobile-first; every breakpoint upgrade is additive.
    · Touch targets ≥ 40 px; safe-area insets honoured.
    · prefers-reduced-motion respected.

  Usage:
      @include('admin.partials.theme')   // inside <head>
============================================================================ --}}

{{-- Fonts: Inter (sans) · JetBrains Mono (mono) — shadcn/ui default family. --}}
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
<link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600&display=swap" rel="stylesheet">

{{-- Tailwind Play CDN — required because this project does not run a Vite
     build for Blade. Config extends shadcn with semantic tone tokens. --}}
<script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio"></script>
<script>
    tailwind.config = {
        darkMode: 'class', // retained for .dark class no-ops; actual palette is light-only
        theme: {
            container: {
                center: true,
                padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' },
                screens: { '2xl': '1400px' },
            },
            extend: {
                fontFamily: {
                    sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
                    display: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                },
                colors: {
                    // ── shadcn base ────────────────────────────────────────
                    // <alpha-value> placeholder makes slash-opacity (bg-x/12, ring-x/30, …) work on the Tailwind Play CDN
                    border:     'hsl(var(--border) / <alpha-value>)',
                    input:      'hsl(var(--input) / <alpha-value>)',
                    ring:       'hsl(var(--ring) / <alpha-value>)',
                    background: 'hsl(var(--background) / <alpha-value>)',
                    foreground: 'hsl(var(--foreground) / <alpha-value>)',
                    primary:    { DEFAULT: 'hsl(var(--primary) / <alpha-value>)',    foreground: 'hsl(var(--primary-foreground) / <alpha-value>)' },
                    secondary:  { DEFAULT: 'hsl(var(--secondary) / <alpha-value>)',  foreground: 'hsl(var(--secondary-foreground) / <alpha-value>)' },
                    destructive:{ DEFAULT: 'hsl(var(--destructive) / <alpha-value>)',foreground: 'hsl(var(--destructive-foreground) / <alpha-value>)' },
                    muted:      { DEFAULT: 'hsl(var(--muted) / <alpha-value>)',      foreground: 'hsl(var(--muted-foreground) / <alpha-value>)' },
                    accent:     { DEFAULT: 'hsl(var(--accent) / <alpha-value>)',     foreground: 'hsl(var(--accent-foreground) / <alpha-value>)' },
                    popover:    { DEFAULT: 'hsl(var(--popover) / <alpha-value>)',    foreground: 'hsl(var(--popover-foreground) / <alpha-value>)' },
                    card:       { DEFAULT: 'hsl(var(--card) / <alpha-value>)',       foreground: 'hsl(var(--card-foreground) / <alpha-value>)' },

                    // ── Semantic risk tones ────────────────────────────────
                    low:        { DEFAULT: 'hsl(var(--low) / <alpha-value>)',        foreground: 'hsl(var(--low-foreground) / <alpha-value>)',        soft: 'hsl(var(--low-soft) / <alpha-value>)' },
                    medium:     { DEFAULT: 'hsl(var(--medium) / <alpha-value>)',     foreground: 'hsl(var(--medium-foreground) / <alpha-value>)',     soft: 'hsl(var(--medium-soft) / <alpha-value>)' },
                    high:       { DEFAULT: 'hsl(var(--high) / <alpha-value>)',       foreground: 'hsl(var(--high-foreground) / <alpha-value>)',       soft: 'hsl(var(--high-soft) / <alpha-value>)' },
                    critical:   { DEFAULT: 'hsl(var(--critical) / <alpha-value>)',   foreground: 'hsl(var(--critical-foreground) / <alpha-value>)',   soft: 'hsl(var(--critical-soft) / <alpha-value>)' },

                    // ── Status tones ───────────────────────────────────────
                    info:       { DEFAULT: 'hsl(var(--info) / <alpha-value>)',       foreground: 'hsl(var(--info-foreground) / <alpha-value>)',       soft: 'hsl(var(--info-soft) / <alpha-value>)' },
                    success:    { DEFAULT: 'hsl(var(--success) / <alpha-value>)',    foreground: 'hsl(var(--success-foreground) / <alpha-value>)',    soft: 'hsl(var(--success-soft) / <alpha-value>)' },
                    warning:    { DEFAULT: 'hsl(var(--warning) / <alpha-value>)',    foreground: 'hsl(var(--warning-foreground) / <alpha-value>)',    soft: 'hsl(var(--warning-soft) / <alpha-value>)' },
                    danger:     { DEFAULT: 'hsl(var(--danger) / <alpha-value>)',     foreground: 'hsl(var(--danger-foreground) / <alpha-value>)',     soft: 'hsl(var(--danger-soft) / <alpha-value>)' },

                    // ── Brand accents ──────────────────────────────────────
                    brand:      { DEFAULT: 'hsl(var(--brand) / <alpha-value>)',      soft: 'hsl(var(--brand-soft) / <alpha-value>)', ink: 'hsl(var(--brand-ink) / <alpha-value>)' },
                },
                borderRadius: {
                    lg:  'var(--radius)',
                    md:  'calc(var(--radius) - 2px)',
                    sm:  'calc(var(--radius) - 4px)',
                    xl:  'calc(var(--radius) + 4px)',
                    '2xl':'calc(var(--radius) + 8px)',
                },
                boxShadow: {
                    'elevation-0': '0 0 0 1px hsl(var(--border))',
                    'elevation-1': '0 1px 2px 0 rgb(16 24 40 / 0.05)',
                    'elevation-2': '0 1px 3px 0 rgb(16 24 40 / 0.10), 0 1px 2px -1px rgb(16 24 40 / 0.06)',
                    'elevation-3': '0 4px 6px -1px rgb(16 24 40 / 0.08), 0 2px 4px -2px rgb(16 24 40 / 0.06)',
                    'elevation-4': '0 10px 20px -3px rgb(16 24 40 / 0.12), 0 4px 8px -4px rgb(16 24 40 / 0.08)',
                    'elevation-5': '0 22px 40px -8px rgb(16 24 40 / 0.18), 0 8px 16px -8px rgb(16 24 40 / 0.10)',
                    'ring-brand': '0 0 0 3px hsl(var(--brand) / .18)',
                    'ring-critical':'0 0 0 3px hsl(var(--critical) / .18)',
                    'inner-soft': 'inset 0 1px 0 0 rgb(255 255 255 / 0.6)',
                },
                /* L11 NO ANIMATIONS AT ALL.
                   All animation utilities are defined as no-ops (`none`) so
                   any class still in markup is inert. Only `animate-spin`
                   remains (browser-default loading indicator) — Tailwind
                   ships it from the core preset so we don't override it. */
                keyframes: {
                    /* intentionally empty — see L11 directive */
                },
                animation: {
                    'accordion-down': 'none',
                    'accordion-up':   'none',
                    'fade-in':        'none',
                    'slide-up':       'none',
                    'slide-in-right': 'none',
                    'pulse-dot':      'none',
                    'shimmer':        'none',
                    'tick':           'none',
                    'ring-pulse':     'none',
                },
                backgroundImage: {
                    'grid-subtle':
                        "linear-gradient(to right, hsl(var(--border)) 1px, transparent 1px), linear-gradient(to bottom, hsl(var(--border)) 1px, transparent 1px)",
                    'dot-subtle':
                        "radial-gradient(hsl(var(--border)) 1px, transparent 1px)",
                    'brand-hero':
                        "linear-gradient(135deg, hsl(var(--brand)) 0%, hsl(var(--info)) 100%)",
                    'brand-soft':
                        "linear-gradient(135deg, hsl(var(--brand-soft)) 0%, hsl(var(--info-soft)) 100%)",
                },
                backgroundSize: {
                    'grid-subtle': '32px 32px',
                    'dot-subtle':  '18px 18px',
                },
            },
        },
    };
</script>

<style type="text/tailwindcss">
    @layer base {
        :root {
            /* ── shadcn base (light, neutral zinc) ─────────────────────── */
            --background:            0 0% 100%;
            --foreground:            240 10% 3.9%;
            --card:                  0 0% 100%;
            --card-foreground:       240 10% 3.9%;
            --popover:               0 0% 100%;
            --popover-foreground:    240 10% 3.9%;
            --primary:               240 5.9% 10%;
            --primary-foreground:    0 0% 98%;
            --secondary:             240 4.8% 95.9%;
            --secondary-foreground:  240 5.9% 10%;
            --muted:                 240 4.8% 95.9%;
            --muted-foreground:      240 3.8% 46.1%;
            --accent:                240 4.8% 95.9%;
            --accent-foreground:     240 5.9% 10%;
            --destructive:           0 84.2% 60.2%;
            --destructive-foreground:0 0% 98%;
            --border:                240 5.9% 90%;
            --input:                 240 5.9% 90%;
            --ring:                  160 84% 39%;
            --radius:                0.625rem;

            /* ── Brand (emerald · public-health trust) ─────────────────── */
            --brand:                 160 84% 39%;   /* emerald 600 */
            --brand-soft:            152 76% 96%;   /* emerald 50 */
            --brand-ink:             163 94% 24%;   /* emerald 800 */

            /* ── Risk tones ────────────────────────────────────────────── */
            --low:                   142 71% 45%;   /* emerald 500 */
            --low-foreground:        0 0% 100%;
            --low-soft:              138 76% 96%;

            --medium:                38 92% 50%;    /* amber 500 */
            --medium-foreground:     26 83% 14%;
            --medium-soft:           48 96% 94%;

            --high:                  21 90% 48%;    /* orange 600 */
            --high-foreground:       0 0% 100%;
            --high-soft:             33 100% 96%;

            --critical:              350 82% 51%;   /* rose 600 */
            --critical-foreground:   0 0% 100%;
            --critical-soft:         351 100% 96%;

            /* ── Status tones ──────────────────────────────────────────── */
            --info:                  199 89% 48%;   /* sky 500 */
            --info-foreground:       0 0% 100%;
            --info-soft:             204 94% 94%;

            --success:               142 71% 45%;
            --success-foreground:    0 0% 100%;
            --success-soft:          138 76% 96%;

            --warning:               38 92% 50%;
            --warning-foreground:    26 83% 14%;
            --warning-soft:          48 96% 94%;

            --danger:                0 84% 60%;
            --danger-foreground:     0 0% 100%;
            --danger-soft:           0 93% 96%;

            /* ── Data-viz palette (Okabe-Ito-inspired, colour-blind safe) ─ */
            --viz-1: 199 89% 48%;    /* sky */
            --viz-2: 160 84% 39%;    /* emerald */
            --viz-3: 38 92% 50%;     /* amber */
            --viz-4: 262 83% 58%;    /* violet */
            --viz-5: 350 82% 51%;    /* rose */
            --viz-6: 27 96% 61%;     /* orange */
            --viz-7: 173 58% 39%;    /* teal */
            --viz-8: 292 47% 51%;    /* purple */
        }

        /* Dark mode intentionally DISABLED — project directive: light only.
           The selector is retained so any leftover .dark class in markup
           is a silent no-op rather than an error. */
        .dark {
            --background:            0 0% 100%;
            --foreground:            240 10% 3.9%;
            --card:                  0 0% 100%;
            --card-foreground:       240 10% 3.9%;
            --popover:               0 0% 100%;
            --popover-foreground:    240 10% 3.9%;
            --primary:               240 5.9% 10%;
            --primary-foreground:    0 0% 98%;
            --secondary:             240 4.8% 95.9%;
            --secondary-foreground:  240 5.9% 10%;
            --muted:                 240 4.8% 95.9%;
            --muted-foreground:      240 3.8% 46.1%;
            --accent:                240 4.8% 95.9%;
            --accent-foreground:     240 5.9% 10%;
            --destructive:           0 84.2% 60.2%;
            --destructive-foreground:0 0% 98%;
            --border:                240 5.9% 90%;
            --input:                 240 5.9% 90%;
            --ring:                  160 84% 39%;
        }

        * { @apply border-border; }
        html, body { @apply h-full; }
        body {
            @apply bg-background text-foreground antialiased;
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            font-feature-settings: 'rlig' 1, 'calt' 1, 'ss01' 1, 'cv11' 1;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }
        [x-cloak] { display: none !important; }
        ::selection { background: hsl(var(--brand) / .22); color: hsl(var(--brand-ink)); }
        *:focus-visible {
            @apply outline-none ring-2 ring-ring ring-offset-2 ring-offset-background;
        }
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        .pt-safe { padding-top:    env(safe-area-inset-top); }
        .pl-safe { padding-left:   env(safe-area-inset-left); }
        .pr-safe { padding-right:  env(safe-area-inset-right); }

        /* Custom scrollbar — unobtrusive on desktop, untouched on mobile */
        .scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: hsl(var(--muted-foreground) / .28); border-radius: 3px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: hsl(var(--muted-foreground) / .55); }

        /* Typography rhythm helpers */
        .display-xl { @apply text-3xl sm:text-4xl font-bold tracking-tight leading-[1.1]; }
        .display-lg { @apply text-2xl sm:text-3xl font-bold tracking-tight leading-[1.15]; }
        .display-md { @apply text-xl sm:text-2xl font-semibold tracking-tight leading-[1.2]; }
        .eyebrow    { @apply text-[11px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
    }

    /* ── Component primitives (shadcn/ui parity) ──────────────────────── */
    @layer components {

        /* ── Button ──────────────────────────────────────────────────────
           Default size is SMALL per project directive (h-9 px-3). */
        .btn {
            @apply inline-flex items-center justify-center gap-2 whitespace-nowrap
                   rounded-md text-sm font-medium ring-offset-background
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2
                   disabled:pointer-events-none disabled:opacity-50
                   h-9 px-3;
        }
        .btn-xs       { @apply h-8 rounded-md px-2.5 text-xs gap-1.5; }
        .btn-sm       { @apply h-9 rounded-md px-3 text-sm; }
        .btn-md       { @apply h-10 rounded-md px-4 text-sm; }
        .btn-lg       { @apply h-11 rounded-md px-6 text-base; }
        .btn-icon     { @apply h-9 w-9 p-0; }
        .btn-icon-xs  { @apply h-8 w-8 p-0; }
        .btn-icon-lg  { @apply h-10 w-10 p-0; }

        .btn-default     { @apply bg-primary text-primary-foreground hover:bg-primary/90 shadow-elevation-1; }
        .btn-brand       { @apply bg-brand text-white hover:bg-brand/90 shadow-elevation-2; }
        .btn-destructive { @apply bg-critical text-critical-foreground hover:bg-critical/90 shadow-elevation-1; }
        .btn-success     { @apply bg-success text-success-foreground hover:bg-success/90 shadow-elevation-1; }
        .btn-outline     { @apply border border-input bg-background hover:bg-accent hover:text-accent-foreground; }
        .btn-secondary   { @apply bg-secondary text-secondary-foreground hover:bg-secondary/80; }
        .btn-soft-brand  { @apply bg-brand-soft text-brand-ink hover:bg-brand-soft/70; }
        .btn-soft-info   { @apply bg-info-soft text-info hover:bg-info-soft/70; }
        .btn-ghost       { @apply hover:bg-accent hover:text-accent-foreground; }
        .btn-link        { @apply text-brand underline-offset-4 hover:underline; }

        /* Input / textarea / select */
        .input {
            @apply flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm
                   file:border-0 file:bg-transparent file:text-sm file:font-medium
                   placeholder:text-muted-foreground
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:border-ring
                   disabled:cursor-not-allowed disabled:opacity-50;
        }
        .textarea {
            @apply flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm
                   placeholder:text-muted-foreground
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:border-ring
                   disabled:cursor-not-allowed disabled:opacity-50;
        }
        .select {
            @apply flex h-9 w-full items-center justify-between whitespace-nowrap rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm ring-offset-background
                   placeholder:text-muted-foreground
                   focus:outline-none focus:ring-2 focus:ring-ring/60
                   disabled:cursor-not-allowed disabled:opacity-50
                   [&>span]:line-clamp-1;
        }

        /* Label / help text */
        .label       { @apply text-sm font-medium leading-none; }
        .description { @apply text-sm text-muted-foreground; }
        .help-text   { @apply text-[0.8rem] text-muted-foreground; }

        /* Card */
        .card              { @apply rounded-xl border bg-card text-card-foreground shadow-elevation-1; }
        .card-hover        { @apply hover:shadow-elevation-3; }
        .card-header       { @apply flex flex-col space-y-1.5 p-5 sm:p-6; }
        .card-title        { @apply text-base font-semibold leading-none tracking-tight; }
        .card-description  { @apply text-sm text-muted-foreground; }
        .card-content      { @apply p-5 pt-0 sm:p-6 sm:pt-0; }
        .card-footer       { @apply flex items-center p-5 pt-0 sm:p-6 sm:pt-0; }
        .card-glass        { @apply bg-white/75 backdrop-blur-xl backdrop-saturate-150; }

        /* Badge (all tones) */
        .badge {
            @apply inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold
                   whitespace-nowrap
                   focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2;
        }
        .badge-default     { @apply border-transparent bg-primary/5 text-primary; }
        .badge-secondary   { @apply border-transparent bg-secondary text-secondary-foreground; }
        .badge-outline     { @apply text-foreground; }
        .badge-low         { @apply border-low/20 bg-low-soft text-low; }
        .badge-medium      { @apply border-medium/20 bg-medium-soft text-medium; }
        .badge-high        { @apply border-high/20 bg-high-soft text-high; }
        .badge-critical    { @apply border-critical/20 bg-critical-soft text-critical; }
        .badge-info        { @apply border-info/20 bg-info-soft text-info; }
        .badge-success     { @apply border-success/20 bg-success-soft text-success; }
        .badge-warning     { @apply border-warning/20 bg-warning-soft text-warning; }
        .badge-danger      { @apply border-danger/20 bg-danger-soft text-danger; }
        .badge-brand       { @apply border-brand/25 bg-brand-soft text-brand-ink; }
        .badge-soon        { @apply border-dashed border-muted-foreground/30 bg-muted/40 text-muted-foreground; }

        /* Risk chip (larger, iconic) */
        .risk-chip {
            @apply inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-wide;
        }
        .risk-chip-low      { @apply bg-low-soft text-low ring-1 ring-inset ring-low/20; }
        .risk-chip-medium   { @apply bg-medium-soft text-medium ring-1 ring-inset ring-medium/20; }
        .risk-chip-high     { @apply bg-high-soft text-high ring-1 ring-inset ring-high/20; }
        .risk-chip-critical { @apply bg-critical-soft text-critical ring-1 ring-inset ring-critical/25; }

        /* Status dot (used inline; pair with pulse to indicate live state) */
        .status-dot         { @apply inline-block h-2 w-2 rounded-full; }
        .status-dot-live    { @apply bg-success shadow-[0_0_0_3px_hsl(var(--success)/.18)]; }
        .status-dot-warn    { @apply bg-warning; }
        .status-dot-danger  { @apply bg-critical shadow-[0_0_0_3px_hsl(var(--critical)/.22)]; }

        /* Separator */
        .separator   { @apply shrink-0 bg-border; }
        .separator-h { @apply h-px w-full; }
        .separator-v { @apply h-full w-px; }

        /* Tabs */
        .tabs-list {
            @apply inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground;
        }
        .tabs-trigger {
            @apply inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium ring-offset-background
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2
                   disabled:pointer-events-none disabled:opacity-50
                   data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow;
        }
        .tabs-content {
            @apply mt-3 ring-offset-background
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2;
        }

        /* Dialog */
        .dialog-overlay  { @apply fixed inset-0 z-50 bg-black/40; }
        .dialog-content  { @apply fixed left-1/2 top-1/2 z-50 grid w-full max-w-lg translate-x-[-50%] translate-y-[-50%]
                                   gap-4 border bg-background p-6 shadow-elevation-5 sm:rounded-xl; }
        .dialog-header   { @apply flex flex-col space-y-1.5 text-left; }
        .dialog-footer   { @apply flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2 pt-2; }
        .dialog-title    { @apply text-lg font-semibold leading-none tracking-tight; }
        .dialog-description{ @apply text-sm text-muted-foreground; }

        /* Sheet (side drawer) */
        .sheet-overlay { @apply fixed inset-0 z-50 bg-black/40; }
        .sheet-content { @apply fixed z-50 gap-4 bg-background p-5 sm:p-6 shadow-elevation-5; }
        .sheet-right   { @apply inset-y-0 right-0 h-full w-[92vw] max-w-[420px] border-l; }
        .sheet-left    { @apply inset-y-0 left-0  h-full w-[92vw] max-w-[380px] border-r; }
        .sheet-top     { @apply inset-x-0 top-0 border-b; }
        .sheet-bottom  { @apply inset-x-0 bottom-0 border-t; }
        .sheet-header  { @apply flex flex-col space-y-1 text-left; }
        .sheet-footer  { @apply flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2; }
        .sheet-title   { @apply text-base font-semibold text-foreground; }
        .sheet-description { @apply text-sm text-muted-foreground; }

        /* Popover / Dropdown */
        .popover-content {
            @apply z-50 w-72 rounded-lg border bg-popover p-3 text-popover-foreground shadow-elevation-4 outline-none;
        }
        .dropdown-content {
            @apply z-50 min-w-[10rem] overflow-hidden rounded-lg border bg-popover p-1 text-popover-foreground shadow-elevation-4;
        }
        .dropdown-item {
            @apply relative flex cursor-default select-none items-center gap-2 rounded-md px-2.5 py-2 text-sm outline-none
                   hover:bg-accent hover:text-accent-foreground
                   focus:bg-accent focus:text-accent-foreground
                   data-[disabled]:pointer-events-none data-[disabled]:opacity-50;
        }
        .dropdown-label     { @apply px-2.5 py-1.5 text-xs font-semibold; }
        .dropdown-separator { @apply -mx-1 my-1 h-px bg-border; }
        .dropdown-shortcut  { @apply ml-auto text-[11px] tracking-wider opacity-60; }

        /* Table */
        .table-wrap     { @apply w-full overflow-auto rounded-lg border; }
        .table          { @apply w-full caption-bottom text-sm; }
        .table-head     { @apply [&_tr]:border-b bg-muted/40; }
        .table-head-th  { @apply h-10 px-3 text-left align-middle text-xs font-semibold uppercase tracking-wider text-muted-foreground; }
        .table-body     { @apply [&_tr:last-child]:border-0; }
        .table-row      { @apply border-b hover:bg-muted/40 data-[state=selected]:bg-muted; }
        .table-cell     { @apply px-3 py-2.5 align-middle; }

        /* Toast */
        .toast {
            @apply pointer-events-auto relative flex w-full items-center justify-between gap-4 overflow-hidden rounded-lg border bg-background p-4 pr-6 shadow-elevation-4;
        }
        .toast-success     { @apply border-success/30 bg-success-soft; }
        .toast-warning     { @apply border-warning/30 bg-warning-soft; }
        .toast-destructive { @apply border-critical bg-critical text-critical-foreground; }
        .toast-title       { @apply text-sm font-semibold; }
        .toast-description { @apply text-sm opacity-90; }
        .toast-action      { @apply inline-flex h-8 shrink-0 items-center justify-center rounded-md border bg-transparent px-3 text-xs font-medium ring-offset-background hover:bg-secondary; }

        /* Alert (inline banner) */
        .alert              { @apply relative w-full rounded-lg border p-4 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg~*]:pl-8; }
        .alert-info         { @apply border-info/30 bg-info-soft [&>svg]:text-info; }
        .alert-success      { @apply border-success/30 bg-success-soft [&>svg]:text-success; }
        .alert-warning      { @apply border-warning/30 bg-warning-soft [&>svg]:text-warning; }
        .alert-critical     { @apply border-critical/40 bg-critical-soft [&>svg]:text-critical; }
        .alert-destructive  { @apply border-destructive/40 [&>svg]:text-destructive text-destructive
                                     [background-color:hsl(var(--destructive)/0.08)]; }
        .alert-title        { @apply mb-1 font-semibold leading-none tracking-tight; }
        .alert-description  { @apply text-sm [&_p]:leading-relaxed text-foreground/80; }

        /* Skeleton (static placeholder per L11 — no shimmer) */
        .skeleton {
            @apply rounded-md bg-muted;
        }

        /* Progress */
        .progress        { @apply relative h-2 w-full overflow-hidden rounded-full bg-muted; }
        .progress-bar    { @apply h-full flex-1 bg-brand; }
        .progress-sm     { @apply h-1.5; }
        .progress-lg     { @apply h-3; }

        /* Switch */
        .switch {
            @apply inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent shadow-sm
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background
                   disabled:cursor-not-allowed disabled:opacity-50
                   data-[state=checked]:bg-brand data-[state=unchecked]:bg-input;
        }
        .switch-thumb {
            @apply pointer-events-none block h-4 w-4 rounded-full bg-background shadow-lg ring-0
                   data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0;
        }

        /* Keyboard chip */
        .kbd {
            @apply pointer-events-none inline-flex h-5 select-none items-center gap-1 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground;
        }

        /* Command palette items */
        .command-list         { @apply max-h-[420px] overflow-y-auto overflow-x-hidden scrollbar-thin; }
        .command-empty        { @apply py-10 text-center text-sm text-muted-foreground; }
        .command-group        { @apply overflow-hidden p-2 text-foreground; }
        .command-group-heading{ @apply px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground; }
        .command-item         { @apply relative flex cursor-default select-none items-center gap-2 rounded-md px-2.5 py-2 text-sm outline-none
                                       aria-selected:bg-accent aria-selected:text-accent-foreground
                                       data-[disabled]:pointer-events-none data-[disabled]:opacity-50; }
        .command-shortcut     { @apply ml-auto text-[11px] tracking-wider text-muted-foreground; }

        /* ── Premium chrome (layout surfaces) ─────────────────────────── */

        /* KPI tile */
        .kpi {
            @apply relative rounded-xl border bg-card p-4 sm:p-5 shadow-elevation-1 overflow-hidden;
        }
        .kpi-label { @apply text-[11px] font-semibold uppercase tracking-wider text-muted-foreground; }
        .kpi-value { @apply mt-1 text-2xl sm:text-3xl font-bold tracking-tight; }
        .kpi-delta { @apply inline-flex items-center gap-1 text-xs font-medium; }
        .kpi-delta-up   { @apply text-success; }
        .kpi-delta-down { @apply text-critical; }
        .kpi-delta-flat { @apply text-muted-foreground; }
        /* Highlight the most-important KPI in a row (subtle brand wash + ring + corner accent). */
        .kpi-glow {
            @apply ring-1 ring-brand/30 shadow-elevation-2
                   [background-image:radial-gradient(120%_60%_at_0%_0%,hsl(var(--brand)/0.08),transparent_60%),radial-gradient(80%_60%_at_100%_100%,hsl(var(--brand)/0.05),transparent_60%)];
        }
        .kpi-glow .kpi-label { @apply text-brand-ink; }
        .kpi-glow .kpi-value { @apply text-foreground; }

        /* Filter chip — used by ownership / case-room timeline filter banks. */
        .chip {
            @apply inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[10.5px] leading-tight
                   cursor-pointer select-none transition-colors;
        }
        .chip-on  { @apply border-brand bg-brand-soft text-brand-ink; }
        .chip-off { @apply border-border bg-transparent text-muted-foreground hover:bg-muted/40; }
        .kpi-glow::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(120% 80% at 100% 0%, hsl(var(--brand)/.08), transparent 60%);
            pointer-events: none;
        }

        /* ────────────────────────────────────────────────────────────
           Sidebar navigation — premium with restraint. Brand color carries
           the active state (gradient accent bar + soft fill + glow) and
           tints hovers; everything else stays neutral so the brand pops.
           ──────────────────────────────────────────────────────────── */
        .sidebar-rail        { @apply h-full w-full flex flex-col bg-background; }
        .sidebar-brand       {
            @apply flex items-center gap-2.5 px-4 py-4 border-b border-border/60
                   bg-gradient-to-br from-brand-soft/30 via-background to-background;
        }
        .sidebar-section     {
            @apply px-3 pt-5 pb-1 text-[10px] font-bold uppercase tracking-[.16em] text-foreground/55 flex items-center gap-2;
        }
        .sidebar-section::before {
            content: '';
            display: inline-block;
            width: 0.625rem;
            height: 1px;
            background: linear-gradient(90deg, hsl(var(--brand)) 0%, hsl(var(--brand)/0) 100%);
        }
        .sidebar-scroll      { @apply flex-1 overflow-y-auto scrollbar-thin px-2 pb-4; }
        .sidebar-user        {
            @apply mt-auto border-t border-border/60 p-2.5
                   bg-gradient-to-t from-brand-soft/20 to-transparent;
        }

        .nav-item {
            @apply relative flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[12.5px] font-medium text-foreground/75
                   transition-all duration-150
                   hover:bg-brand-soft/45 hover:text-brand-ink
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring;
        }
        .nav-item-icon   {
            @apply h-[15px] w-[15px] shrink-0 text-muted-foreground/60 transition-colors
                   group-hover:text-brand;
        }
        .nav-item-label  { @apply flex-1 truncate; }

        /* Active: brand-soft fill + brand-ink text + glowing gradient accent bar. */
        .nav-item-active {
            @apply text-brand-ink bg-brand-soft/75 font-semibold;
            box-shadow: inset 0 0 0 1px hsl(var(--brand) / .12);
        }
        .nav-item-active::before {
            content: '';
            position: absolute;
            left: -2px;
            top: 0.375rem;
            bottom: 0.375rem;
            width: 3px;
            background: linear-gradient(180deg, hsl(var(--brand)) 0%, hsl(var(--info)) 100%);
            border-radius: 999px;
            box-shadow: 0 0 10px hsl(var(--brand) / .40);
        }
        .nav-item-active .nav-item-icon { @apply text-brand; }

        /* Live (non-active): tiny brand dot trailing the label so the eye
           can tell at a glance which items are reachable today. */
        .nav-item-live-dot { @apply ml-auto h-1.5 w-1.5 rounded-full bg-brand/65 shrink-0; }

        /* Soon: dimmer text + dashed circle indicator instead of the brand dot. */
        .nav-item-soon   { @apply text-muted-foreground/60; }
        .nav-item-soon .nav-item-icon { @apply text-muted-foreground/45; }
        .nav-item-soon:hover { @apply text-foreground/80 bg-muted/40; }
        .nav-item-soon-dot { @apply ml-auto h-1.5 w-1.5 rounded-full border border-dashed border-muted-foreground/45 shrink-0; }

        /* ────────────────────────────────────────────────────────────
           Topbar — single-line premium row. No stacked title block.
           Page title left, live page-meta chips + scope right.
           ──────────────────────────────────────────────────────────── */
        .topbar {
            @apply sticky top-0 z-30 flex h-12 items-center gap-3 border-b border-border/60 bg-background/80 backdrop-blur-xl backdrop-saturate-150 px-3 sm:px-5;
        }
        .topbar-chip {
            @apply inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-transparent px-2 py-0.5 text-[10.5px] font-medium text-muted-foreground;
        }
        .topbar-chip-mono { @apply font-mono tabular-nums; }
        .topbar-crumb {
            @apply text-[10.5px] font-medium text-muted-foreground/65 truncate;
        }
        .topbar-title {
            @apply text-[14px] font-semibold text-foreground truncate leading-tight;
        }

        /* Kanban primitives intentionally REMOVED (2026-04-23).
           Directive: status boards use slim premium tables (.table-wrap /
           .table / .table-row) with tabs + filter chips, or card grids
           (.card). Never column-per-status boards. */

        /* Ring gauge wrapper (SVG inside) */
        .ring-gauge     { @apply relative inline-flex items-center justify-center; }
        .ring-gauge-label { @apply absolute inset-0 flex flex-col items-center justify-center text-center; }

        /* Empty / Loading shells */
        .empty-state { @apply flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed bg-muted/30 py-16 px-6 text-center; }
        .empty-icon  { @apply h-10 w-10 text-muted-foreground/60; }

        /* Decorative backdrops (use sparingly on hero/brand areas) */
        .bg-grid-soft {
            background-image:
              linear-gradient(to right, hsl(var(--border)/.55) 1px, transparent 1px),
              linear-gradient(to bottom, hsl(var(--border)/.55) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        .bg-dots-soft {
            background-image: radial-gradient(hsl(var(--border)) 1px, transparent 1.2px);
            background-size: 16px 16px;
        }
    }
</style>
