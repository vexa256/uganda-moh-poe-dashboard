{{--
  x-ui.table · shadcn-identical table wrapper
  ---------------------------------------------------------------
  Thin shell; callers supply their own <thead> / <tbody> markup.

    <x-ui.table>
      <thead class="table-head">
        <tr class="border-b">
          <th class="table-head-th">…</th>
        </tr>
      </thead>
      <tbody class="table-body">
        <tr class="table-row"><td class="table-cell">…</td></tr>
      </tbody>
    </x-ui.table>
--}}
<div {{ $attributes->class(['table-wrap']) }}>
    <table class="table">
        {{ $slot }}
    </table>
</div>
