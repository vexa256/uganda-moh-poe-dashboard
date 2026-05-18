<?php

/*
|--------------------------------------------------------------------------
| Blade tiny helpers · PHEOC admin shell
|--------------------------------------------------------------------------
| Tone → Tailwind class maps used by anonymous Blade components
| (nav-item, nav-sub, badge, etc.). Autoloaded via composer.json "files".
*/

if (! function_exists('pheoc_badge_classes')) {
    function pheoc_badge_classes(string $tone): string
    {
        return match ($tone) {
            'emerald' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
            'amber'   => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
            'rose'    => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
            'brand'   => 'bg-brand-500/20 text-brand-200 border-brand-400/30',
            'sky'     => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
            'violet'  => 'bg-violet-500/15 text-violet-300 border-violet-500/30',
            default   => 'bg-ink-700/60 text-ink-300 border-ink-600',
        };
    }
}

if (! function_exists('pheoc_dot_classes')) {
    function pheoc_dot_classes(string $tone): string
    {
        return match ($tone) {
            'emerald' => 'bg-emerald-400',
            'amber'   => 'bg-amber-400',
            'rose'    => 'bg-rose-400',
            'brand'   => 'bg-brand-400',
            'sky'     => 'bg-sky-400',
            'violet'  => 'bg-violet-400',
            default   => 'bg-ink-500',
        };
    }
}
