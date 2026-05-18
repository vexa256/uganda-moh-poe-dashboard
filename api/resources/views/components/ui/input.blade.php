{{--
  x-ui.input · shadcn-identical input (forwarded attributes)
  Usage: <x-ui.input type="email" name="email" required />
--}}
@props(['type' => 'text'])
<input type="{{ $type }}" {{ $attributes->class(['input']) }}>
