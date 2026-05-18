{{--
  x-ui.chart · Chart.js-4 wrapper (lazy-loads the library on first use)
  ---------------------------------------------------------------
  Props:
    payload  (required) Chart.js config produced by ChartPayloadBuilder
    height   pixel height of the canvas container (default 260)
    id       dom id (auto-generated if omitted)
    title    optional card title
    caption  optional caption

  Chart.js is lazy-injected once per page. Multiple <x-ui.chart> on
  the same page reuse the library.
--}}
@props([
    'payload' => null,
    'height'  => 260,
    'id'      => null,
    'title'   => null,
    'caption' => null,
])

@php
    $id = $id ?? 'chart-' . uniqid();
    $payloadJson = htmlspecialchars(json_encode($payload ?: []), ENT_QUOTES, 'UTF-8');
@endphp

<div {{ $attributes->class(['card']) }}>
    @if($title || $caption)
        <div class="card-header">
            @if($title)<p class="card-title">{{ $title }}</p>@endif
            @if($caption)<p class="card-description">{{ $caption }}</p>@endif
        </div>
    @endif
    <div class="card-content">
        <div style="height: {{ (int) $height }}px; position: relative;">
            <canvas id="{{ $id }}" role="img" aria-label="{{ $title ?? 'Chart' }}"></canvas>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    @endpush
@endonce

@push('scripts')
    <script>
        (function () {
            function renderChart() {
                if (typeof Chart === 'undefined') return setTimeout(renderChart, 60);
                var el = document.getElementById(@json($id));
                if (!el) return;
                try {
                    var payload = JSON.parse(@json(json_encode($payload ?: [])));
                    new Chart(el.getContext('2d'), payload);
                } catch (e) { console.warn('chart render failed', e); }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', renderChart);
            } else { renderChart(); }
        })();
    </script>
@endpush
