{{--
  x-ui.badge · shadcn-identical badge
  ---------------------------------------------------------------
  Two usage modes:
    (1) Explicit variant:
        <x-ui.badge variant="success">Synced</x-ui.badge>

    (2) Semantic kind+code (auto-translates via EnumTranslator):
        <x-ui.badge kind="risk" :code="$alert->risk_level" />

  Variants: default, brand, secondary, outline, low, medium, high,
            critical, info, success, warning, danger, soon

  Kinds (for auto-tone+label): risk, ihr, alert_status,
    followup_status, notification_status, severity
--}}
@props([
    'variant' => 'default',
    'kind'    => null,           // if set, we use EnumTranslator to resolve tone + label
    'code'    => null,           // enum code when using `kind`
    'dot'     => false,          // prepend a status dot
])

@php
    if ($kind !== null && $code !== null) {
        $translator = app(\App\Support\EnumTranslator::class);
        $variant = $translator->tone($kind, (string) $code) ?: 'default';
        $label   = $translator->label($kind, (string) $code);
    } else {
        $label = null;
    }

    $variantClass = match($variant) {
        'brand'        => 'badge-brand',
        'secondary'    => 'badge-secondary',
        'outline'      => 'badge-outline',
        'low'          => 'badge-low',
        'medium'       => 'badge-medium',
        'high'         => 'badge-high',
        'critical'     => 'badge-critical',
        'info'         => 'badge-info',
        'success'      => 'badge-success',
        'warning'      => 'badge-warning',
        'danger'       => 'badge-danger',
        'soon'         => 'badge-soon',
        default        => 'badge-default',
    };

    $dotClass = match($variant) {
        'low'      => 'bg-low',
        'medium'   => 'bg-medium',
        'high'     => 'bg-high',
        'critical' => 'bg-critical',
        'info'     => 'bg-info',
        'success'  => 'bg-success',
        'warning'  => 'bg-warning',
        'danger'   => 'bg-danger',
        'brand'    => 'bg-brand',
        default    => 'bg-muted-foreground',
    };
@endphp

<span {{ $attributes->class(['badge', $variantClass]) }}>
    @if($dot)
        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    @endif
    {{ $label ?? $slot }}
</span>
