<?php

namespace App\Providers;

use App\View\Composers\ScopeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Inject $scope + $currentUser into every admin view automatically.
        View::composer('admin.*', ScopeComposer::class);
    }
}
