<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light only">
    <title>Link unavailable · POE Sentinel</title>
    @include('admin.partials.theme')
</head>
<body class="min-h-screen grid place-items-center bg-muted/40 p-6">
    <div class="card max-w-md w-full">
        <div class="card-content !p-6 sm:!p-8 text-center space-y-3">
            <div class="grid place-items-center h-12 w-12 mx-auto rounded-full bg-warning-soft text-warning">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.66 1.73-2.99L13.73 4.99a2 2 0 00-3.46 0L3.34 16.01C2.57 17.34 3.53 19 5.07 19z"/></svg>
            </div>
            <h1 class="text-xl font-bold">Link unavailable</h1>
            <p class="text-[13px] text-muted-foreground">{{ $message }}</p>
            <p class="text-[11px] text-muted-foreground pt-3">If you need to reply to a request, please ask the surveillance team for a fresh link.</p>
        </div>
    </div>
</body>
</html>
