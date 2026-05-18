{{--
  x-ui.select · shadcn-identical select
  Usage:
    <x-ui.select name="country">
      <option value="UG">Uganda</option>
    </x-ui.select>
--}}
<select {{ $attributes->class(['select']) }}>{{ $slot }}</select>
