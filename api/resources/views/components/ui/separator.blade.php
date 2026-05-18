{{--
  x-ui.separator · thin semantic divider (horizontal default).
  Pass orientation="vertical" for a 1px vertical rule inside flex rows.
--}}
@props(['orientation' => 'horizontal'])
<div {{ $attributes->class([
    'separator',
    'separator-h' => $orientation !== 'vertical',
    'separator-v' => $orientation === 'vertical',
]) }} role="separator"></div>
