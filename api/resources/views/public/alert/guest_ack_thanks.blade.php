@extends('public.alert._layout', ['title' => 'Acknowledged · ' . ($alert->alert_code ?? 'Alert')])
@section('body')
<section class="panel p-8 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-2xl font-bold">✓</div>
    <h1 class="text-[20px] font-bold text-slate-900 mt-3">Acknowledgement recorded</h1>
    <p class="text-[14px] text-slate-700 mt-2">
        Thank you, {{ $recipient }}. The Uganda POE Sentinel response team for alert
        <strong>{{ $alert->alert_code }}</strong> has been notified that you have received this alert and are taking action.
    </p>
    <p class="text-[12px] text-slate-500 mt-4">
        This guest link has been retired. Need to take further action? Request a fresh link from the alert sender or
        ask your administrator to set up a Uganda POE Sentinel account.
    </p>
</section>
@endsection
