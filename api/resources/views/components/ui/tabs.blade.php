{{--
  x-ui.tabs · Alpine-driven tab set with URL query-param state
  ---------------------------------------------------------------
  Child components:  <x-ui.tab name="..." label="...">content</x-ui.tab>

  Usage:
    <x-ui.tabs name="view" default="timeline">
      <x-ui.tab name="timeline" label="Timeline">...</x-ui.tab>
      <x-ui.tab name="collaborators" label="Collaborators">...</x-ui.tab>
    </x-ui.tabs>

  The `?view=timeline` URL query parameter is mirrored via window.history.
  State lives in a single Alpine root component (no x-data nesting).
--}}
@props([
    'name'    => 'tab',
    'default' => null,
])

@php
    // Default to the first tab if not provided; pre-read from the request.
    $current = request()->query($name, $default);
@endphp

<div
    x-data="{
        active: @js($current),
        urlKey: @js($name),
        activate(tab) {
            this.active = tab;
            try {
                const u = new URL(window.location.href);
                u.searchParams.set(this.urlKey, tab);
                window.history.replaceState({}, '', u);
            } catch (e) {}
        },
    }"
    {{ $attributes->class(['w-full']) }}
>
    {{ $slot }}
</div>
