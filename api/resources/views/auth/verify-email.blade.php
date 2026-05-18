{{-- /verify-email?token=…&email=… · posts once to /api/v2/auth/verify-email/confirm --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify email · {{ config('app.name', 'PHEOC Uganda') }}</title>
@include('admin.partials.theme')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-full bg-background text-foreground antialiased" x-cloak
      x-data="verifyEmail()" x-init="run()">

<main class="min-h-screen grid place-items-center p-4 sm:p-6">
    <div class="w-full max-w-sm space-y-6">

        <div class="text-center space-y-2">
            <div class="inline-flex h-9 w-9 rounded-md bg-primary text-primary-foreground items-center justify-center">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h1 class="text-lg font-semibold tracking-tight">PHEOC Uganda</h1>
                <p class="text-xs text-muted-foreground">Email verification</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content p-6 text-center space-y-3">

                {{-- Loading --}}
                <template x-if="state === 'loading'">
                    <div class="space-y-3">
                        <div class="mx-auto h-10 w-10 rounded-full bg-muted flex items-center justify-center">
                            <svg class="h-5 w-5 animate-spin text-muted-foreground" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold">Checking your link</h2>
                            <p class="description mt-1">Just a moment…</p>
                        </div>
                    </div>
                </template>

                {{-- Success --}}
                <template x-if="state === 'done'">
                    <div class="space-y-3">
                        <div class="mx-auto h-10 w-10 rounded-full bg-primary text-primary-foreground flex items-center justify-center">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold">Email confirmed</h2>
                            <p class="description mt-1">Taking you to your dashboard…</p>
                        </div>
                    </div>
                </template>

                {{-- Error --}}
                <template x-if="state === 'error'">
                    <div class="space-y-3">
                        <div class="mx-auto h-10 w-10 rounded-full bg-destructive/10 text-destructive flex items-center justify-center">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold">We couldn't confirm this link</h2>
                            <p class="description mt-1" x-text="err || 'Ask an administrator to send a new verification email.'"></p>
                        </div>
                        <a href="{{ url('/admin/login') }}" class="btn btn-outline btn-xs">Go to sign-in</a>
                    </div>
                </template>

            </div>
        </div>

    </div>
</main>

<script>
function verifyEmail(){
    return {
        state: 'loading',
        err: '',
        token: new URLSearchParams(location.search).get('token') || '',
        email: new URLSearchParams(location.search).get('email') || '',
        async run(){
            if (!this.token || !this.email) { this.state='error'; this.err='This link is missing details. Please use the link from your email.'; return; }
            try {
                const r = await fetch('{{ url('/api/v2/auth/verify-email/confirm') }}', {
                    method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json'},
                    body: JSON.stringify({ token:this.token, email:this.email }),
                });
                const b = await r.json().catch(()=>({}));
                if (r.ok && b.ok) {
                    this.state = 'done';
                    setTimeout(()=> location.replace('{{ url('/admin/dashboard') }}'), 1200);
                    return;
                }
                this.state = 'error';
                this.err = b?.error || 'This link may have expired.';
            } catch(e) {
                this.state = 'error';
                this.err = 'Network problem — try once more.';
            }
        },
    };
}
</script>
</body>
</html>
