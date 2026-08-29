<?php

namespace App\Providers;

use App\Enums\JourneyModule;
use App\Listeners\EndLoginSession;
use App\Listeners\StartLoginSession;
use App\Models\Customer;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Services\Journey\CustomerJourneyAccessService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
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
         * Server-side authority for Manager-stage journey actions. Delegates
         * entirely to CustomerJourneyAccessService so there is a single
         * source of truth shared by canEdit()/getEloquentQuery() checks and
         * any explicit $user->can() call in an action handler.
         */
        Gate::define(
            'perform-journey-action',
            fn (User $user, Customer $customer, JourneyModule $module) => app(CustomerJourneyAccessService::class)
                ->decide($user, $customer, $module)
                ->allowed
        );

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
