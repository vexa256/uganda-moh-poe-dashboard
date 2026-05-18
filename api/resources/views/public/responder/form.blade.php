<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light only">
    <title>POE Sentinel · Information Request</title>
    @include('admin.partials.theme')
    <style>
        .auth-shell {
            min-height: 100svh;
            background:
                radial-gradient(1200px 600px at 100% -20%, hsl(var(--brand) / .12), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, hsl(var(--info) / .10), transparent 60%),
                linear-gradient(180deg, hsl(var(--background)) 0%, hsl(var(--muted) / .4) 100%);
        }
    </style>
</head>
<body class="auth-shell">
<main class="mx-auto max-w-3xl px-4 sm:px-8 py-10">

    <header class="mb-8">
        <div class="flex items-center gap-3">
            <div class="grid place-items-center h-10 w-10 rounded-lg bg-brand-soft text-brand-ink">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand">Uganda POE Sentinel</p>
                <h1 class="text-xl font-bold">Information request</h1>
            </div>
        </div>
        <p class="text-[12.5px] text-muted-foreground mt-2">A surveillance officer has requested your input on an active alert. Your response will be added to the case file. This link is one-time-use and expires soon.</p>
    </header>

    {{-- Identity card --}}
    <section class="card mb-5">
        <div class="card-content !p-4 grid grid-cols-1 sm:grid-cols-2 gap-2 text-[12.5px]">
            <div>
                <p class="text-muted-foreground">Recipient</p>
                <p class="font-semibold">{{ $responder->name ?? '—' }}@if(! empty($responder->organisation)) <span class="text-muted-foreground"> · {{ $responder->organisation }}</span>@endif</p>
                <p class="text-[11px] text-muted-foreground">{{ $responder->responder_type ?? '' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Subject</p>
                <p class="font-semibold">{{ $request->request_subject }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Alert</p>
                <p><span class="font-mono text-[11.5px]">{{ $alert->alert_code ?? '—' }}</span> · <span class="badge" data-risk="{{ $alert->risk_level ?? '' }}">{{ $alert->risk_level ?? '' }}</span></p>
                <p class="text-[11.5px]">{{ $alert->alert_title ?? '' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Expires</p>
                <p class="text-[11.5px]">{{ $expires_at ? \Illuminate\Support\Carbon::parse($expires_at)->toDayDateTimeString() : 'no expiry' }}</p>
            </div>
        </div>
    </section>

    {{-- Original message from team --}}
    <section class="card mb-5">
        <div class="card-header">
            <h3 class="card-title text-[13px]">Message from the surveillance team</h3>
        </div>
        <div class="card-content !pt-0">
            <p class="text-[12.5px] whitespace-pre-wrap">{{ $request->request_body }}</p>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-destructive mb-4">
            <p class="alert-title">We could not save your reply</p>
            <ul class="alert-description list-disc pl-5">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/respond/'.$token) }}" enctype="multipart/form-data" class="card" x-data="{submitting:false}" @submit="submitting = true">
        @csrf
        <div class="card-content !p-5 space-y-4">

            <div>
                <label class="label">Summary of your response <span class="text-critical">*</span></label>
                <textarea name="response_summary" class="input mt-1.5" rows="4" required minlength="5" maxlength="5000" placeholder="What did you find? What action have you taken so far?">{{ old('response_summary') }}</textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Lab findings (optional)</label>
                    <textarea name="lab_findings" class="input mt-1.5" rows="3" maxlength="5000" placeholder="Specimen results, pathogen identified, Ct values…">{{ old('lab_findings') }}</textarea>
                </div>
                <div>
                    <label class="label">Sample results (optional)</label>
                    <textarea name="sample_results" class="input mt-1.5" rows="3" maxlength="5000" placeholder="Reference numbers, batch IDs, dates collected">{{ old('sample_results') }}</textarea>
                </div>
            </div>

            <div>
                <label class="label">Next actions / commitments (optional)</label>
                <textarea name="next_actions" class="input mt-1.5" rows="2" maxlength="2000" placeholder="What will you do next? When?">{{ old('next_actions') }}</textarea>
            </div>

            <div>
                <label class="label">Best contact for callback (optional)</label>
                <input name="contact_callback" class="input mt-1.5" maxlength="200" placeholder="Phone, email, name" value="{{ old('contact_callback') }}">
            </div>

            <div>
                <label class="label">Attachment (optional · ≤ 25 MB)</label>
                <input name="attachment" type="file" class="input mt-1.5" accept="{{ $allowed_ext }}">
                <p class="text-[10.5px] text-muted-foreground mt-1">Allowed: PDF · PNG · JPG · HEIC · WEBP · CSV · TXT · XLSX · DOCX</p>
            </div>

            <label class="flex items-start gap-2 cursor-pointer text-[12.5px]">
                <input type="checkbox" name="consent_share" value="1" class="mt-0.5" required>
                <span>I consent to sharing this response with the Uganda POE surveillance team. I understand my submission is recorded in the case audit log.</span>
            </label>
        </div>

        <div class="border-t px-5 py-3 flex items-center gap-2">
            <p class="text-[10.5px] text-muted-foreground flex-1">One-time submission · this link expires after use.</p>
            <button type="submit" class="btn btn-brand" :disabled="submitting">
                <span x-show="!submitting">Submit response</span>
                <span x-show="submitting">Sending…</span>
            </button>
        </div>
    </form>

    <footer class="mt-8 text-center text-[11px] text-muted-foreground">
        © {{ date('Y') }} Uganda National Public Health Institute · POE Sentinel
    </footer>
</main>
</body>
</html>
