@extends('public.alert._layout', ['title' => 'Account inactive'])
@section('body')
<section class="panel p-8 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-2xl font-bold">!</div>
    <h1 class="text-[20px] font-bold text-slate-900 mt-3">Your account is currently inactive</h1>
    <p class="text-[14px] text-slate-700 mt-2 max-w-md mx-auto">
        Uganda POE Sentinel is not signing you in because your account is suspended or has not yet been activated.
        Your data is safe. Please contact your administrator to restore access.
    </p>
    <div class="mt-5 text-[12px] text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 inline-block text-left">
        <strong>Reactivation contacts</strong><br>
        UNIPH National Administrator · admin@pheoc.go.ug<br>
        Uganda POE Sentinel ops · vexa256@gmail.com / ayebare.k.timothy@gmail.com
    </div>
    <div class="mt-6">
        <a href="{{ url('/login') }}" class="btn btn-ghost">Try sign in again</a>
    </div>
</section>
@endsection
