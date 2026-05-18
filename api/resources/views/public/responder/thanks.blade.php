<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light only">
    <title>Response received · POE Sentinel</title>
    @include('admin.partials.theme')
</head>
<body class="min-h-screen grid place-items-center bg-muted/40 p-6">
    <div class="card max-w-md w-full">
        <div class="card-content !p-6 sm:!p-8 text-center space-y-3">
            <div class="grid place-items-center h-12 w-12 mx-auto rounded-full bg-success-soft text-success">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
            </div>
            <h1 class="text-xl font-bold">Response received</h1>
            <p class="text-[13px] text-muted-foreground">
                Thank you{{ $name ? ', '.$name : '' }}. Your reply has been added to the case file. The surveillance team will follow up if more information is needed.
            </p>
            <p class="text-[11px] text-muted-foreground pt-3">This link is now closed. You may safely close this tab.</p>
        </div>
    </div>
</body>
</html>
