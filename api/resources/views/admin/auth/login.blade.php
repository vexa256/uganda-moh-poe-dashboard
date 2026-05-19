{{-- ============================================================================
  Admin · Auth · Login — Republic of Uganda · Ministry of Health
  ----------------------------------------------------------------------------
  Enterprise government adaptation. Follows international standards for
  public-sector authentication surfaces:
    · Official-site identifier strip      (GOV.UK / USA.gov / Australia.gov pattern)
    · National flag accent ribbon          (Uganda: black · yellow · red)
    · Coat-of-arms wordmark lockup         (Republic of Uganda · Ministry of Health)
    · Authorised-use warning notice        (NIST 800-53 AC-8 · OMB M-04-26 pattern)
    · WCAG 2.1 AA accessibility            (skip link, ARIA, focus rings, contrast)
    · Browser security context strip       (HTTPS lock, session timeout)
  ----------------------------------------------------------------------------
  Server contract preserved verbatim:
    · POST {{ url('/login') }} via LoginController::login
    · @csrf  ·  name="identifier"  ·  name="password"  ·  name="remember"
    · @error('identifier'|'password')   ·   session('status') flash
    · old('identifier', request()->cookie('admin_last_email')) pre-fill
============================================================================ --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light only">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex,nofollow">
    <meta name="description" content="Authorised sign-in for the Uganda Ministry of Health Public Health Emergency Operations Centre — Points of Entry surveillance.">
    <title>Sign in · Ministry of Health · Republic of Uganda</title>

    @include('admin.partials.theme')

    <style>
        /* ─────────────────────────────────────────────────────────────
           Government identifier strip — black, with subtle texture.
           Matches the convention used by GOV.UK / USA.gov / Australia.gov
           to declare "this is an official government website".
           ───────────────────────────────────────────────────────────── */
        .gov-strip {
            background: #111418;
            color: #f5f7fa;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }
        .gov-strip a:focus-visible { outline: 2px solid #FCDC04; outline-offset: 2px; border-radius: 2px; }

        /* National flag ribbon — Uganda: black · yellow · red · black · yellow · red */
        .ug-flag-ribbon {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            height: 4px;
        }
        .ug-flag-ribbon > i:nth-child(1) { background: #000000; }
        .ug-flag-ribbon > i:nth-child(2) { background: #FCDC04; }
        .ug-flag-ribbon > i:nth-child(3) { background: #D90000; }
        .ug-flag-ribbon > i:nth-child(4) { background: #000000; }
        .ug-flag-ribbon > i:nth-child(5) { background: #FCDC04; }
        .ug-flag-ribbon > i:nth-child(6) { background: #D90000; }

        /* Auth shell — quieter than the previous emerald hero; this is government
           chrome, not a marketing splash. */
        .auth-shell {
            min-height: 100svh;
            background:
                radial-gradient(900px 500px at 100% -10%, hsl(var(--brand) / .08), transparent 60%),
                radial-gradient(700px 400px at -10% 110%, hsl(var(--info) / .07), transparent 60%),
                linear-gradient(180deg, hsl(var(--background)) 0%, hsl(var(--muted) / .35) 100%);
        }
        .auth-hero {
            background:
                radial-gradient(700px 500px at 15% 0%, rgba(252,220,4,.10), transparent 60%),
                radial-gradient(600px 400px at 85% 100%, rgba(217,0,0,.10), transparent 60%),
                linear-gradient(135deg, #0b1220 0%, #111827 55%, #0f172a 100%);
        }
        .auth-hero-grid {
            background-image:
                linear-gradient(to right, rgb(255 255 255 / .04) 1px, transparent 1px),
                linear-gradient(to bottom, rgb(255 255 255 / .04) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* Authorised-use notice — bordered, neutral, conspicuous but not alarming. */
        .auth-notice {
            border: 1px solid hsl(var(--border));
            background: hsl(var(--muted) / .35);
            border-left: 3px solid #FCDC04;
            border-radius: 8px;
        }

        /* Skip-to-main accessibility link — visible only when focused. */
        .skip-link {
            position: absolute; left: -9999px; top: 0;
            background: #111418; color: #fff;
            padding: 10px 14px; border-radius: 0 0 6px 0;
            font-size: 13px; font-weight: 600; z-index: 100;
        }
        .skip-link:focus { left: 0; outline: 2px solid #FCDC04; outline-offset: 2px; }

        /* Coat-of-arms mini-mark — abstracted, respectful stylisation built from
           three flag bands inside a shield outline. Not a reproduction of the
           official armorial bearings; serves as a wordmark anchor only. */
        .coa-mark { width: 44px; height: 52px; }
    </style>
</head>
<body class="h-full bg-background text-foreground antialiased">

<a href="#main" class="skip-link">Skip to sign-in form</a>

{{-- ── Official-site identifier strip ───────────────────────────────────── --}}
<div class="gov-strip">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-2 flex items-center gap-3 text-[11.5px] leading-tight">
        {{-- Tiny flag chip --}}
        <span aria-hidden="true" class="inline-flex h-3 w-4 overflow-hidden rounded-[2px] ring-1 ring-white/15 shrink-0">
            <span class="block w-full h-1/3" style="background:#000000"></span>
        </span>
        <span class="inline-flex h-3 w-4 -ml-3 overflow-hidden rounded-[2px] ring-1 ring-white/15 shrink-0" aria-hidden="true">
            <span class="block w-full h-1/3"                          style="background:#000000"></span>
            <span class="block w-full h-1/3" style="background:#FCDC04"></span>
            <span class="block w-full h-1/3" style="background:#D90000"></span>
        </span>
        <p class="font-semibold tracking-tight text-white">
            An official website of the <span class="underline decoration-white/30 underline-offset-2">Government of Uganda</span>
        </p>
        <span class="hidden sm:inline text-white/40">·</span>
        <p class="hidden sm:inline text-white/70">
            Secure <span class="font-mono text-[11px] text-white">https://</span> connection · You are on a restricted system.
        </p>
        <span class="ml-auto hidden md:inline-flex items-center gap-1.5 text-white/70">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <span>TLS · audited session</span>
        </span>
    </div>
    {{-- National flag ribbon --}}
    <div class="ug-flag-ribbon" role="presentation" aria-hidden="true">
        <i></i><i></i><i></i><i></i><i></i><i></i>
    </div>
</div>

<div class="auth-shell flex items-stretch justify-center">

    {{-- ── Hero (desktop only) ───────────────────────────────────── --}}
    <aside class="auth-hero hidden lg:flex lg:w-1/2 relative overflow-hidden" aria-hidden="true">
        <div class="auth-hero-grid absolute inset-0 opacity-70"></div>
        <div class="relative z-10 flex flex-col justify-between p-12 text-white w-full">

            {{-- Official lockup: Coat-of-arms mark + wordmark --}}
            <div class="flex items-start gap-4">
                <svg class="coa-mark" viewBox="0 0 44 52" fill="none" aria-hidden="true">
                    {{-- Shield outline --}}
                    <path d="M22 1 L42 7 V26 C42 38 33 47 22 51 C11 47 2 38 2 26 V7 Z"
                          fill="rgba(255,255,255,.06)" stroke="#FCDC04" stroke-width="1.5"/>
                    {{-- Three flag bands inside --}}
                    <rect x="6"  y="10" width="32" height="9"  fill="#000000"/>
                    <rect x="6"  y="20" width="32" height="9"  fill="#FCDC04"/>
                    <rect x="6"  y="30" width="32" height="9"  fill="#D90000"/>
                    {{-- White disc (suggests crane) --}}
                    <circle cx="22" cy="24.5" r="5" fill="#ffffff" stroke="#000000" stroke-width="0.8"/>
                </svg>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[.22em] text-white/60">Republic of Uganda</p>
                    <p class="text-[18px] font-bold tracking-tight leading-tight mt-0.5">Ministry of Health</p>
                    <p class="text-[11px] font-medium tracking-[.14em] uppercase text-[#FCDC04]/90 mt-1">PHEOC · National Command</p>
                </div>
            </div>

            {{-- Centred hero copy --}}
            <div class="max-w-md">
                <p class="text-[11px] font-semibold uppercase tracking-[.2em] text-white/70">IHR-2005 · IDSR 3rd Edition</p>
                <h1 class="mt-3 text-[30px] font-bold tracking-tight leading-[1.1]">
                    Points-of-Entry surveillance for the <span class="text-[#FCDC04]">Pearl of Africa</span>.
                </h1>
                <p class="mt-4 text-[13px] leading-relaxed text-white/80">
                    Authorised personnel sign in here to monitor traveller screening, manage public-health alerts and track 7-1-7 response performance across every airport, land border and lake port in Uganda — scoped to your jurisdiction.
                </p>
                <ul class="mt-5 space-y-1.5 text-[12px] text-white/75">
                    <li class="flex items-center gap-2"><span class="inline-block h-1 w-1 rounded-full bg-[#FCDC04]"></span> WHO National IHR Focal Point workspace</li>
                    <li class="flex items-center gap-2"><span class="inline-block h-1 w-1 rounded-full bg-[#FCDC04]"></span> Jurisdiction-scoped data · server-enforced</li>
                    <li class="flex items-center gap-2"><span class="inline-block h-1 w-1 rounded-full bg-[#FCDC04]"></span> Every action audited · IHR-2005 §D.5</li>
                </ul>
            </div>

            {{-- Footer meta --}}
            <div class="flex items-center justify-between text-[11px] text-white/60">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-success"></span>
                    <span>System operational</span>
                    <span class="text-white/30">·</span>
                    <span class="font-mono">v10 bundle</span>
                </div>
                <p class="font-medium text-white/70">For God and My Country</p>
            </div>
        </div>
    </aside>

    {{-- ── Form column ───────────────────────────────────────────── --}}
    <main id="main" class="flex-1 flex items-center justify-center px-5 sm:px-8 py-10 lg:w-1/2">
        <div class="w-full max-w-sm">

            {{-- Mobile-only official lockup --}}
            <div class="flex items-center gap-3 mb-7 lg:hidden">
                <svg class="h-11 w-9" viewBox="0 0 44 52" fill="none" aria-hidden="true">
                    <path d="M22 1 L42 7 V26 C42 38 33 47 22 51 C11 47 2 38 2 26 V7 Z"
                          fill="#0b1220" stroke="#FCDC04" stroke-width="1.5"/>
                    <rect x="6"  y="10" width="32" height="9"  fill="#000000"/>
                    <rect x="6"  y="20" width="32" height="9"  fill="#FCDC04"/>
                    <rect x="6"  y="30" width="32" height="9"  fill="#D90000"/>
                    <circle cx="22" cy="24.5" r="5" fill="#ffffff" stroke="#000000" stroke-width="0.8"/>
                </svg>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[.18em] text-muted-foreground">Republic of Uganda</p>
                    <p class="text-[14px] font-bold tracking-tight leading-tight">Ministry of Health</p>
                    <p class="text-[10px] font-semibold tracking-[.14em] uppercase text-brand leading-tight">PHEOC · National</p>
                </div>
            </div>

            <header class="mb-6">
                <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted-foreground/70">Authorised sign-in</p>
                <h2 class="mt-1 text-[22px] font-bold tracking-tight leading-tight">Sign in to PHEOC</h2>
                <p class="mt-1.5 text-[13px] text-muted-foreground leading-relaxed">
                    Use your Ministry-issued credentials to access POE surveillance scoped to your jurisdiction.
                </p>
            </header>

            {{-- Flash messages --}}
            @if (session('status'))
                <div class="alert alert-success mb-4" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                    <div><p class="alert-description">{{ session('status') }}</p></div>
                </div>
            @endif

            @if ($errors->any() && ! $errors->has('identifier') && ! $errors->has('password'))
                <div class="alert alert-critical mb-4" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                    <div><p class="alert-description">{{ $errors->first() }}</p></div>
                </div>
            @endif

            <form method="POST" action="{{ url('/login') }}" class="space-y-4"
                  x-data="{ show: false, submitting: false }"
                  @submit="submitting = true"
                  aria-describedby="auth-notice">
                @csrf

                <div>
                    <label for="identifier" class="label flex items-center justify-between">
                        <span>Email or username</span>
                        @error('identifier')
                            <span class="text-[11px] text-critical" id="identifier-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <input type="text"
                           id="identifier"
                           name="identifier"
                           required
                           aria-required="true"
                           @error('identifier') aria-invalid="true" aria-describedby="identifier-error" @enderror
                           autofocus
                           autocomplete="username"
                           spellcheck="false"
                           autocapitalize="off"
                           value="{{ old('identifier', request()->cookie('admin_last_email')) }}"
                           class="input mt-1.5 @error('identifier') border-critical focus-visible:ring-critical/50 @enderror"
                           placeholder="you@health.go.ug">
                </div>

                <div>
                    <label for="password" class="label flex items-center justify-between">
                        <span>Password</span>
                        @error('password')
                            <span class="text-[11px] text-critical" id="password-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <div class="relative mt-1.5">
                        <input :type="show ? 'text' : 'password'"
                               id="password"
                               name="password"
                               required
                               aria-required="true"
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                               autocomplete="current-password"
                               class="input pr-10 @error('password') border-critical focus-visible:ring-critical/50 @enderror"
                               placeholder="••••••••">
                        <button type="button"
                                @click="show = !show"
                                class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                                :aria-label="show ? 'Hide password' : 'Show password'"
                                :aria-pressed="show">
                            <svg x-show="!show"  class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.06 10.06 0 0112 20c-7 0-11-8-11-8a18.43 18.43 0 014.42-5.94M9.9 4.24A10.9 10.9 0 0112 4c7 0 11 8 11 8a18.3 18.3 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-input text-brand focus:ring-ring/60">
                        <span class="text-[12.5px] text-foreground/80">Keep me signed in on this device</span>
                    </label>
                    <span class="text-[11.5px] text-muted-foreground/60">Reset via PHEOC Admin</span>
                </div>

                <button type="submit"
                        :disabled="submitting"
                        class="w-full btn btn-brand h-11 text-[13.5px] font-semibold shadow-[0_4px_14px_-3px_hsl(var(--brand)/.40)] relative focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60">
                    <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                        Sign in securely
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </span>
                    <span x-show="submitting" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                        Verifying credentials…
                    </span>
                </button>
            </form>

            {{-- Authorised-use notice (NIST 800-53 AC-8 pattern) --}}
            <div id="auth-notice" class="auth-notice mt-6 p-3.5 text-[11.5px] leading-relaxed text-foreground/80">
                <p class="font-semibold text-foreground flex items-center gap-1.5">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-critical" aria-hidden="true">
                        <path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    </svg>
                    Authorised use only · Restricted system
                </p>
                <p class="mt-1.5 text-muted-foreground">
                    This is a Government of Uganda information system provided for official Ministry of Health business.
                    All activity is monitored, logged and subject to audit. Unauthorised access or misuse is prohibited under the
                    Computer Misuse Act and may result in disciplinary, civil and criminal action.
                </p>
            </div>

            {{-- Access tier reference --}}
            <div class="mt-5 text-[11.5px] text-muted-foreground/80 leading-relaxed">
                <p>
                    <span class="font-semibold text-foreground/80">Access tiers:</span>
                    <span class="font-mono text-[10.5px]">NATIONAL_ADMIN</span> sees all jurisdictions ·
                    <span class="font-mono text-[10.5px]">PHEOC_OFFICER</span> sees their region ·
                    <span class="font-mono text-[10.5px]">DISTRICT_SUPERVISOR</span> sees their district.
                </p>
                <p class="mt-2 text-[10.5px] text-muted-foreground/60">
                    Every write is audited · Scope enforced server-side · RBAC per IHR-2005 §D.5.
                </p>
            </div>
        </div>
    </main>

</div>

{{-- ── Official footer ──────────────────────────────────────────── --}}
<footer class="border-t border-border bg-muted/30">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-[11px] text-muted-foreground">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold text-foreground/80">Ministry of Health · Republic of Uganda</span>
            <span class="text-muted-foreground/40">·</span>
            <span>Plot 6, Lourdel Road, Nakasero · P.O. Box 7272, Kampala</span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <span>© {{ date('Y') }} Government of Uganda</span>
            <span class="text-muted-foreground/40">·</span>
            <span>UNIPH · National IHR Focal Point</span>
            <span class="text-muted-foreground/40">·</span>
            <span class="font-mono">v10</span>
        </div>
    </div>
</footer>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
