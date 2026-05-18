{{--
  x-ui.label · form label
  Usage: <x-ui.label for="email">Email</x-ui.label>
--}}
@props(['for' => null, 'required' => false])
<label @if($for) for="{{ $for }}" @endif {{ $attributes->class(['label inline-flex items-center gap-1']) }}>
    {{ $slot }}
    @if($required)
        <span class="text-critical" aria-label="required">*</span>
    @endif
</label>
