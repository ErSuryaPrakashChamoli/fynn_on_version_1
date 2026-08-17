<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Listeners\EndLoginSession;
use App\Listeners\StartLoginSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use App\Models\Customer;
use App\Observers\CustomerObserver;

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
        //

        Event::listen(
            Login::class,
            StartLoginSession::class
        );

        Event::listen(
            Logout::class,
            EndLoginSession::class
        );

        Customer::observe(CustomerObserver::class);
    }
}
