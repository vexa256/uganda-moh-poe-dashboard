{{--
  x-ui.risk-chip · pill-shaped risk indicator for kanban cards,
                   war-room header, alert feed rows
  ---------------------------------------------------------------
  Props:
    level   LOW|MEDIUM|HIGH|CRITICAL  (case-insensitive)
    pulse   bool — add a subtle pulse for CRITICAL (default: auto-on-critical)
    compact bool — smaller footprint

  The chip automatically translates the level to plain English via EnumTranslator.
--}}
@props([
    'level' => 'MEDIUM',
    'pulse' => null,         // null=auto, true/false override
    'compact' => false,
])

@php
    $translator = app(\App\Support\EnumTranslator::class);
    $label = $translator->riskLevel((string) $level);
    $tone  = $translator->riskTone((string) $level);

    $toneClass = "risk-chip-{$tone}";
    $sizeClass = $compact ? '!text-[10.5px] !px-2 !py-0.5' : '';

    $dotClass = match($tone) {
        'low'      => 'bg-low',
        'medium'   => 'bg-medium',
        'high'     => 'bg-high',
        'critical' => 'bg-critical',
        default    => 'bg-muted-foreground',
    };
@endphp

<span {{ $attributes->class(['risk-chip', $toneClass, $sizeClass]) }}>
    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    {{ $label }}
</span>
