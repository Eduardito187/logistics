<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Icons\DistrackIconSet;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Íconos internos de Filament → set propio DisTrack (ds-*).
        FilamentIcon::register(DistrackIconSet::filamentAliases());
    }
}
