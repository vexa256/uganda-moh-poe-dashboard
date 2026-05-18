{{-- ============================================================================
  Public · Invite acceptance — premium · mobile-first · split-screen on desktop
  Reuses admin.partials.theme (the design tokens are public).
============================================================================ --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light only">
    <meta name="theme-color" content="#ffffff">
    <meta name="robots" content="noindex,nofollow">
    <title>Accept invitation · PHEOC Command Centre</title>

    @include('admin.partials.theme')

    <style>
        .auth-shell {
            min-height: 100svh;
            background:
                radial-gradient(1200px 600px at 100% -20%, hsl(var(--brand) / .12), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, hsl(var(--info) / .10), transparent 60%),
                linear-gradient(180deg, hsl(var(--background)) 0%, hsl(var(--muted) / .4) 100%);
        }
        .auth-hero {
            background:
                radial-gradient(600px 400px at 20% 0%, hsl(var(--brand) / .35), transparent 70%),
                linear-gradient(135deg, hsl(var(--brand-ink)) 0%, hsl(var(--brand)) 55%, hsl(var(--info)) 100%);
        }
        .auth-hero-grid {
            background-image:
                linear-gradient(to right, rgb(255 255 255 / .05) 1px, transparent 1px),
                linear-gradient(to bottom, rgb(255 255 255 / .05) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .pw-meter > span { transition: width 200ms ease, background-color 200ms ease; }
    </style>
</head>
<body class="auth-shell">
<div class="grid lg:grid-cols-2 min-h-screen">

    {{-- Hero --}}
    <aside class="auth-hero text-white p-8 lg:p-12 hidden lg:flex flex-col justify-between relative overflow-hidden">
        <div class="absolute inset-0 auth-hero-grid opacity-50"></div>
        <div class="relative z-10 flex items-center gap-3">
            <div class="grid place-items-center h-10 w-10 rounded-lg bg-white/10 backdrop-blur"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
            <div><p class="font-bold tracking-tight">PHEOC Command Centre</p><p class="text-[11px] opacity-80">Uganda National POE Surveillance</p></div>
        </div>
        <div class="relative z-10 max-w-md space-y-5">
            <span class="badge bg-white/15 text-white border-white/20">Welcome</span>
            <h1 class="text-3xl xl:text-4xl font-bold leading-tight">You've been invited to the surveillance workforce.</h1>
            <p class="text-[14px] opacity-90 leading-relaxed">Set a password to activate your account. Once active, you'll have access to the dashboards, alerts, and operational records scoped to your jurisdiction.</p>
            <ul class="space-y-2 text-[12.5px] opacity-90">
                <li class="flex items-start gap-2"><span class="text-success">✓</span><span>WHO IHR-2005 / IDSR-aligned tooling</span></li>
                <li class="flex items-start gap-2"><span class="text-success">✓</span><span>RBAC: every screen scoped to your role</span></li>
                <li class="flex items-start gap-2"><span class="text-success">✓</span><span>Audit-trail on every action you take</span></li>
            </ul>
        </div>
        <div class="relative z-10 text-[11px] opacity-70">© {{ date('Y') }} UNIPH · Uganda National Institute of Public Health</div>
    </aside>

    {{-- Form --}}
    <main class="flex flex-col justify-center p-6 sm:p-10 lg:p-16">
        <div class="mx-auto w-full max-w-md" x-data="acceptInvite()" x-init="boot()">
            <header class="mb-6">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand">Accept invitation</p>
                <h2 class="text-2xl font-bold mt-1">Activate your account</h2>
                <p class="text-[12.5px] text-muted-foreground mt-1">
                    Set a password for <span class="font-semibold text-foreground">{{ $user->full_name }}</span> to finish onboarding.
                </p>
            </header>

            {{-- User identity card --}}
            <section class="card mb-5">
                <div class="card-content !p-4 space-y-1.5 text-[12px]">
                    <div class="flex justify-between"><span class="text-muted-foreground">Full name</span><span class="font-semibold" >{{ $user->full_name }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Username</span><span class="font-mono">@{{ $user->username }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Email</span><span>{{ $user->email }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Role</span><span class="badge badge-outline">{{ $role_label }}</span></div>
                    @if (! empty($expires_at))
                        <div class="flex justify-between"><span class="text-muted-foreground">Invitation expires</span><span class="text-[11.5px]">{{ \Illuminate\Support\Carbon::parse($expires_at)->toDayDateTimeString() }}</span></div>
                    @endif
                </div>
            </section>

            @if ($errors->any())
                <div class="alert alert-destructive mb-4">
                    <p class="alert-title">We couldn't activate your account</p>
                    <ul class="alert-description list-disc pl-5">
                        @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/invite/'.$token) }}" class="space-y-4" @submit="submitting=true">
                @csrf

                <div>
                    <label class="label">New password <span class="text-critical">*</span></label>
                    <div class="relative mt-1.5">
                        <input :type="show ? 'text' : 'password'" name="password" class="input pr-10" required minlength="10" autocomplete="new-password" x-model="pw" @input="score()">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground" @click="show=!show" aria-label="Toggle visibility">
                            <svg x-show="!show" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show"  class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 014.06-5.94M9.9 4.24A10.94 10.94 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-3.16 4.19M9.88 9.88a3 3 0 104.24 4.24M1 1l22 22"/></svg>
                        </button>
                    </div>

                    {{-- Strength meter --}}
                    <div class="mt-2 pw-meter h-1.5 rounded-full bg-muted overflow-hidden">
                        <span class="block h-full" :style="`width:${meter.pct}%; background-color: hsl(var(--${meter.color}))`"></span>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between text-[11px]">
                        <span :class="`text-${meter.color}`" x-text="meter.label"></span>
                        <span class="text-muted-foreground">at least 10 chars · mix of upper / lower / digit / symbol</span>
                    </div>
                </div>

                <div>
                    <label class="label">Confirm password <span class="text-critical">*</span></label>
                    <input type="password" name="password_confirmation" class="input mt-1.5" required minlength="10" autocomplete="new-password" x-model="pw2">
                    <p class="text-[11px] text-critical mt-1" x-show="pw2 && pw !== pw2">Passwords do not match.</p>
                </div>

                <label class="flex items-start gap-2 cursor-pointer text-[12.5px]">
                    <input type="checkbox" name="accept_terms" value="1" required class="mt-0.5" x-model="terms">
                    <span>I understand my activity is recorded in the audit log and that I will only access data within my assigned jurisdiction.</span>
                </label>

                <button type="submit"
                        class="btn btn-brand w-full"
                        :disabled="submitting || pw.length < 10 || pw !== pw2 || !terms || meter.pct < 50">
                    <span x-show="!submitting">Activate account</span>
                    <span x-show="submitting">Activating…</span>
                </button>
            </form>

            <p class="text-[11px] text-muted-foreground text-center mt-6">
                Already activated? <a href="{{ url('/login') }}" class="text-brand underline">Sign in</a>
            </p>
        </div>
    </main>
</div>

<script>
function acceptInvite(){
    return {
        pw:'', pw2:'', show:false, submitting:false, terms:false,
        meter:{pct:0,label:'too short',color:'critical'},
        boot(){ this.score(); },
        score(){
            const p=this.pw||''; let s=0;
            if(p.length>=10) s+=25;
            if(p.length>=14) s+=15;
            if(/[a-z]/.test(p)) s+=15;
            if(/[A-Z]/.test(p)) s+=15;
            if(/\d/.test(p))    s+=15;
            if(/[^\w\s]/.test(p)) s+=15;
            // Penalise trivial sequences
            if(/(.)\1{2,}/.test(p)) s-=15;
            if(/^(?:1234|abcd|qwer|password|letmein)/i.test(p)) s-=30;
            s=Math.max(0,Math.min(100,s));
            let label='weak', color='critical';
            if(s>=80){label='strong'; color='success';}
            else if(s>=60){label='good'; color='info';}
            else if(s>=40){label='fair'; color='warning';}
            else {label='weak'; color='critical';}
            this.meter={pct:s,label,color};
        },
    };
}
</script>
</body>
</html>
