{{--
  x-ui.breadcrumb · navigation breadcrumb trail
  Props:
    items  array of [ 'label' => str, 'href' => str|null ]
--}}
@props(['items' => []])

<nav aria-label="Breadcrumb" {{ $attributes->class(['flex items-center gap-1.5 min-w-0']) }}>
    @foreach($items as $i => $it)
        @if($i > 0)
            <svg class="h-3 w-3 text-muted-foreground/50 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        @endif
        @if(!empty($it['href']) && $i < count($items) - 1)
            <a href="{{ $it['href'] }}" class="text-[12.5px] font-medium text-muted-foreground hover:text-foreground truncate">{{ $it['label'] }}</a>
        @else
            <span class="text-[12.5px] font-semibold text-foreground truncate">{{ $it['label'] }}</span>
        @endif
    @endforeach
</nav>
