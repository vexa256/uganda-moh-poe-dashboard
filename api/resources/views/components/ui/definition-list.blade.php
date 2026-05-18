{{--
  x-ui.definition-list · semantic dt/dd for resource detail tabs
  ---------------------------------------------------------------
  Usage:
    <x-ui.definition-list :items="[
        ['label' => 'Risk level', 'value' => $riskLabel],
        ['label' => 'IHR tier',   'value' => $tierLabel],
        ['label' => 'Opened',     'value' => $openedAt, 'hint' => '4 hr ago'],
    ]" />
--}}
@props([
    'items'   => [],
    'columns' => 2,    // 1 or 2
])

@php
    $colClass = $columns === 1 ? '' : 'sm:grid-cols-2';
@endphp

<dl {{ $attributes->class(['grid grid-cols-1 gap-x-6 gap-y-3', $colClass]) }}>
    @foreach($items as $row)
        <div class="flex flex-col">
            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ $row['label'] ?? '' }}
            </dt>
            <dd class="mt-0.5 text-[13.5px] text-foreground/90 flex items-center gap-1.5 flex-wrap">
                <span>{{ $row['value'] ?? '—' }}</span>
                @if(!empty($row['hint']))
                    <span class="text-[11.5px] text-muted-foreground">· {{ $row['hint'] }}</span>
                @endif
                @if(!empty($row['badge']))
                    <x-ui.badge variant="{{ $row['badge_tone'] ?? 'secondary' }}">{{ $row['badge'] }}</x-ui.badge>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
