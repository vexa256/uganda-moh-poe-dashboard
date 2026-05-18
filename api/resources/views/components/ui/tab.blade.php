{{--
  x-ui.tab · single tab trigger + panel pair
  ---------------------------------------------------------------
  Must be nested inside <x-ui.tabs>.
  Props:
    name   url-safe identifier
    label  trigger label
    badge  optional badge text (count, state)
    icon   optional SVG path-d
--}}
@props([
    'name'  => '',
    'label' => '',
    'badge' => null,
    'icon'  => null,
])

{{-- The tab set renders triggers in the first pass; we use a
     shared slot convention: just stack panels and they'll each
     render their own trigger row via a sibling element.  Simpler
     contract: a single tablist header is rendered here for each
     tab, collected visually by flex layout. In practice, consumers
     wrap all <x-ui.tab> in a <x-ui.tabs>, and the shared Alpine
     root reveals the active panel based on `active === name`. --}}

<div class="hidden data-[state=active]:block" x-show="active === @js($name)" x-cloak>
    {{-- panel body --}}
    <div class="tabs-content">
        {{ $slot }}
    </div>
</div>

{{-- placeholder for trigger rendering — callers that want a dedicated
     trigger row should use <x-ui.tab-list> + <x-ui.tab-trigger>. The
     x-ui.tabs-triggers helper below renders them in a single row. --}}
