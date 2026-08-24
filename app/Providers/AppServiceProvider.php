<?php

namespace App\Providers;

use App\Bot\Exchange\BanGuard;
use App\Bot\Exchange\ExchangeManager;
use App\Bot\Exchange\Trader;
use ccxt\Exchange;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public $singletons = [
        BanGuard::class => BanGuard::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Exchange::class, fn ($app) => $app->make(ExchangeManager::class)->create());
        $this->app->singleton(Trader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
