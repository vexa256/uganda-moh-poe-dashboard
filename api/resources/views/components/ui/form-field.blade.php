{{--
  x-ui.form-field · label + control + help + error, one bundle
  ---------------------------------------------------------------
  Props:
    id        input id (auto-linked to label)
    label     visible label
    help      optional help text
    error     optional error message
    required  bool
    hint      short right-aligned hint (e.g. "max 64 chars")
--}}
@props([
    'id'       => null,
    'label'    => null,
    'help'     => null,
    'error'    => null,
    'required' => false,
    'hint'     => null,
])

<div {{ $attributes->class(['space-y-1.5']) }}>
    @if($label)
        <div class="flex items-center justify-between">
            <x-ui.label :for="$id" :required="$required">{{ $label }}</x-ui.label>
            @if($hint)
                <span class="help-text">{{ $hint }}</span>
            @endif
        </div>
    @endif

    {{ $slot }}

    @if($error)
        <p class="text-[12px] text-critical flex items-center gap-1">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ $error }}
        </p>
    @elseif($help)
        <p class="help-text">{{ $help }}</p>
    @endif
</div>
