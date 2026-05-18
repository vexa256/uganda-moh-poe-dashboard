@extends('public.alert._layout', ['title' => 'Link unavailable'])
@section('body')
<section class="panel p-8 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center text-2xl font-bold">!</div>
    <h1 class="text-[20px] font-bold text-slate-900 mt-3">Link unavailable</h1>
    <p class="text-[14px] text-slate-700 mt-2 max-w-md mx-auto">{{ $message }}</p>
    <p class="text-[11px] text-slate-500 mt-4">Reason code: {{ $code }}</p>
    <div class="mt-6">
        <a href="{{ url('/login') }}" class="btn btn-ghost">Sign in</a>
    </div>
</section>
@endsection
