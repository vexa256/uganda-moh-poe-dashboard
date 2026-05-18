@extends('public.alert._layout', ['title' => 'Acknowledge · ' . ($alert->alert_code ?? 'Alert')])
@section('body')
<section class="panel p-6">
    <div class="text-[11px] text-slate-500 uppercase tracking-wider">Acknowledgement</div>
    <h1 class="text-[20px] font-bold text-slate-900 mt-1">{{ $alert->alert_code }}</h1>
    <p class="text-[14px] text-slate-700 mt-1">{{ $alert->alert_title }}</p>

    <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
        <div><span class="text-slate-500">Risk:</span> <strong class="text-slate-900">{{ $alert->risk_level }}</strong></div>
        <div><span class="text-slate-500">Routed:</span> <strong class="text-slate-900">{{ $alert->routed_to_level }}</strong></div>
        <div><span class="text-slate-500">POE:</span> <strong class="text-slate-900">{{ $alert->poe_code ?: '—' }}</strong></div>
        <div><span class="text-slate-500">District:</span> <strong class="text-slate-900">{{ $alert->district_code ?: '—' }}</strong></div>
    </div>

    <hr class="my-5 border-slate-200">

    <form method="POST" action="{{ url('/g/alert/' . $token . '/ack') }}" class="space-y-3">
        @csrf
        <label for="note" class="label">Optional note (visible to the response team)</label>
        <textarea id="note" name="note" rows="3" maxlength="500"
                  class="w-full border border-slate-300 rounded-lg p-3 text-[13.5px]"
                  placeholder="Optional context — e.g. arrival ETA, on-call clinician, capacity status"></textarea>

        <p class="text-[12px] text-slate-600">
            By clicking <strong>Confirm acknowledgement</strong>, you record on the alert audit log that <strong>{{ $recipient }}</strong>
            has received this alert and is taking action. This single-use link will then be retired.
        </p>

        <div class="flex justify-end gap-2">
            <button type="submit" class="btn btn-primary">Confirm acknowledgement</button>
        </div>
    </form>
</section>
@endsection
