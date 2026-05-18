{{-- /reset-password?token=…&email=… · posts to /api/v2/auth/password/reset --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password · {{ config('app.name', 'PHEOC Uganda') }}</title>
@include('admin.partials.theme')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-full bg-background text-foreground antialiased" x-cloak x-data="resetPw()">

<main class="min-h-screen grid place-items-center p-4 sm:p-6">
    <div class="w-full max-w-sm space-y-6">

        <div class="text-center space-y-2">
            <div class="inline-flex h-9 w-9 rounded-md bg-primary text-primary-foreground items-center justify-center">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h1 class="text-lg font-semibold tracking-tight">PHEOC Uganda</h1>
                <p class="text-xs text-muted-foreground">Password reset</p>
            </div>
        </div>

        <template x-if="done">
            <div class="card">
                <div class="card-content p-6 text-center space-y-3">
                    <div class="mx-auto h-10 w-10 rounded-full bg-primary text-primary-foreground flex items-center justify-center">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold">Password updated</h2>
                        <p class="description mt-1">You can sign in with your new password now.</p>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="!done && !token">
            <div class="card">
                <div class="card-content p-6 space-y-3">
                    <div class="alert alert-destructive">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <h4 class="alert-title">This link has expired or is incomplete</h4>
                            <div class="alert-description">Open the email again and use the most recent "Reset password" link.</div>
                        </div>
                    </div>
                    <a href="{{ url('/admin/login') }}" class="btn btn-outline w-full">Back to sign-in</a>
                </div>
            </div>
        </template>

        <template x-if="!done && token">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title text-base">Choose a new password</h2>
                    <p class="card-description">Make it at least 12 characters so it's hard to guess.</p>
                </div>
                <div class="card-content space-y-4">
                    <div x-show="err" class="alert alert-destructive">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div><h4 class="alert-title">We couldn't reset your password</h4><div class="alert-description" x-text="err"></div></div>
                    </div>
                    <form @submit.prevent="submit()" class="space-y-3">
                        <div class="space-y-1.5">
                            <label class="label">Your email</label>
                            <input class="input font-mono text-xs" type="email" x-model="email" readonly>
                        </div>
                        <div class="space-y-1.5">
                            <label class="label">New password</label>
                            <input class="input" type="password" required autofocus autocomplete="new-password"
                                   minlength="12" x-model="password" placeholder="At least 12 characters">
                        </div>
                        <div class="space-y-1.5">
                            <label class="label">Type it again</label>
                            <input class="input" type="password" required autocomplete="new-password"
                                   x-model="password_confirmation">
                            <p class="help-text text-destructive" x-show="password && password_confirmation && password !== password_confirmation">These don't match yet.</p>
                        </div>
                        <button type="submit" class="btn btn-default w-full" :disabled="loading || !valid">
                            <svg x-show="loading" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
                            Update my password
                        </button>
                    </form>
                </div>
            </div>
        </template>

    </div>
</main>

<script>
function resetPw(){
    return {
        token: new URLSearchParams(location.search).get('token') || '',
        email: new URLSearchParams(location.search).get('email') || '',
        password: '', password_confirmation: '',
        loading: false, done: false, err: '',
        get valid(){ return this.token && /@/.test(this.email) && this.password.length >= 12 && this.password === this.password_confirmation; },
        async submit(){
            this.err = '';
            this.loading = true;
            try {
                const r = await fetch('{{ url('/api/v2/auth/password/reset') }}', {
                    method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json'},
                    body: JSON.stringify({ token:this.token, email:this.email, password:this.password, password_confirmation:this.password_confirmation })
                });
                const b = await r.json().catch(()=>({}));
                if (r.ok && b.ok) {
                    this.done = true;
                    setTimeout(()=> location.replace('{{ url('/admin/login') }}'), 1500);
                    return;
                }
                this.err = b?.error || b?.errors?.password?.[0] || 'This link may have expired. Ask an administrator for a new one.';
            } catch(e) {
                this.err = 'Network problem — try once more.';
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
</body>
</html>
