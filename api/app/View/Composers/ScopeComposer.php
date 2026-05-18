<?php

namespace App\View\Composers;

use App\Services\PheocScope;
use Illuminate\View\View;

/**
 * Binds $scope + $currentUser to every admin view.
 *
 * Registered in AppServiceProvider. Any view under `admin.*` can consume
 * `$scope['label']`, `$scope['is_super']`, `$scope['poes']`, etc. without
 * having to pull PheocScope manually in every controller.
 */
class ScopeComposer
{
    public function __construct(protected PheocScope $scope)
    {
    }

    public function compose(View $view): void
    {
        $user = auth()->user();

        if (! $user) {
            $view->with([
                'scope'       => null,
                'currentUser' => null,
            ]);
            return;
        }

        $view->with([
            'scope'       => $this->scope->forUser($user),
            'currentUser' => $user,
        ]);
    }
}
