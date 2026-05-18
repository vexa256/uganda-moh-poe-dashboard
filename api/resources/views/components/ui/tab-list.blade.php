{{--
  x-ui.tab-list · tab trigger row (horizontally scrollable on mobile)
  ---------------------------------------------------------------
  Accepts an array of tab descriptors via :items or uses the slot
  content directly.

  :items is the recommended pattern — makes the trigger row and
  panel body declarations symmetric.

  <x-ui.tab-list :items="[
      ['name' => 'timeline', 'label' => 'Timeline', 'badge' => null],
      ['name' => 'comments', 'label' => 'Comments', 'badge' => 3],
  ]" />
--}}
@props([
    'items' => [],
])

<div {{ $attributes->class(['tabs-list overflow-x-auto scrollbar-thin w-full justify-start !h-auto !py-1']) }} role="tablist">
    @foreach($items as $item)
        <button
            type="button"
            role="tab"
            :aria-selected="active === @js($item['name'])"
            :data-state="active === @js($item['name']) ? 'active' : 'inactive'"
            @click="activate(@js($item['name']))"
            class="tabs-trigger"
        >
            @if(!empty($item['icon']))
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
            @endif
            {{ $item['label'] }}
            @if(!empty($item['badge']))
                <span class="ml-1 inline-flex min-w-[18px] h-[18px] px-1 items-center justify-center rounded-full bg-muted text-muted-foreground text-[10px] font-semibold">
                    {{ $item['badge'] }}
                </span>
            @endif
        </button>
    @endforeach
</div>
