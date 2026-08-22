<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Listeners\EndLoginSession;
use App\Listeners\StartLoginSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        /*
         * Fired by the scheduled `queue:monitor` check in routes/console.php
         * when the default queue backs up past its threshold — usually
         * means the Supervisor-managed workers are down or overwhelmed.
         * MAIL_MAILER is 'log' in this environment (no real outbound
         * channel configured), so this only reaches storage/logs for now;
         * wire a real notification channel here if one gets added later.
         */
        Event::listen(function (QueueBusy $event) {
            Log::critical('Queue backlog exceeds threshold', [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
    }
}
