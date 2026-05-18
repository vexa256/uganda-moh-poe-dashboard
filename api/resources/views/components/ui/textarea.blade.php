{{--
  x-ui.textarea · shadcn-identical textarea
--}}
@props(['rows' => 3])
<textarea rows="{{ $rows }}" {{ $attributes->class(['textarea']) }}>{{ $slot }}</textarea>
