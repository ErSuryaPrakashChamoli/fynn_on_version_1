<?php

namespace App\Providers;

use App\Enums\JourneyModule;
use App\Enums\NotificationCategory;
use App\Listeners\EndLoginSession;
use App\Listeners\StartLoginSession;
use App\Models\Customer;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Services\DailyCommitmentGate;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\MonthlyTargetGate;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Notifications\DatabaseNotification;
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

        /*
         * Shared for the request so the panel middleware and the blocking
         * prompt component answer "are this month's targets fixed?" from
         * one memoized result instead of re-running the same queries on
         * every panel page load.
         */
        $this->app->singleton(MonthlyTargetGate::class);
        $this->app->singleton(DailyCommitmentGate::class);
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
         * Files every bell notification under a tab (see NotificationCategory)
         * at the moment it is stored, whoever sent it — the bell and the
         * reminder pop-up group and filter on this column.
         */
        DatabaseNotification::creating(function (DatabaseNotification $notification): void {
            $data = is_array($notification->data) ? $notification->data : (json_decode((string) $notification->data, true) ?: []);

            $notification->category ??= NotificationCategory::fromNotificationData($data)->value;
        });

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

        $this->registerAmountInWordsMacro();
    }

    /**
     * TextInput::amountInWords() reads a rupee figure back under the field —
     * "₹12,50,000 — Twelve Lakh Fifty Thousand" — so a stray zero or a
     * missing digit is caught before it is saved.
     *
     * It keeps whatever ->helperText() / ->belowContent() the field already
     * has (both share the below-content slot, so it must be chained AFTER
     * them), strips Indian grouping commas before parsing, and makes the
     * field live on blur unless it is already live. Pass a bool or Closure
     * to show the words only conditionally (e.g. a fixed payout, not a %).
     */
    protected function registerAmountInWordsMacro(): void
    {
        TextInput::macro('amountInWords', function (bool|Closure $condition = true): TextInput {
            /** @var TextInput $this */
            $existingBelowContent = $this->childComponents[Field::BELOW_CONTENT_SCHEMA_KEY] ?? null;

            if ($this->isLive === null) {
                $this->live(onBlur: true);
            }

            return $this->belowContent(function (Component $component, $state) use ($condition, $existingBelowContent): array {
                $existing = $component->evaluate($existingBelowContent);

                $components = array_values(array_filter(
                    is_array($existing) ? $existing : [$existing],
                    fn ($item): bool => filled($item),
                ));

                if (! $component->evaluate($condition)) {
                    return $components;
                }

                $amount = preg_replace('/[^0-9.]/', '', (string) (is_scalar($state) ? $state : ''));

                if ($amount === '' || ! is_numeric($amount)) {
                    return $components;
                }

                $components[] = Text::make(indianAmountInWords($amount));

                return $components;
            });
        });
    }
}
