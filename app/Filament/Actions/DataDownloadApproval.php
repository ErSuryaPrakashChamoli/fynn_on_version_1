<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\DataDownloadAuthenticatorService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use SensitiveParameter;

/**
 * The single gate for every export and data download in the panel (user
 * decision 2026-11-14): only an Admin may export anything.
 *
 *  - protect(): lead, customer and assigned-lead data. Admin only, and every
 *    export also needs the owner's authenticator code
 *    (App\Services\DataDownloadAuthenticatorService).
 *  - adminOnly(): everything else (employee hierarchy, performance report,
 *    login sessions, employee data). Admin only, no owner code.
 *
 * A hidden action is also refused on the server: Filament treats it as
 * disabled and will not run it.
 *
 * protect() hides the action from everyone but the Admin (replacing any
 * visible() set on it before) and refuses non-Admins again on the server.
 * The exporter's options form carries codeField(), whose rule checks the
 * code without using it up, so a mistake elsewhere in the form does not
 * burn it. protect() then uses the code up once the form is valid, strips
 * it before the options reach the queued export job, and logs the
 * download. While no authenticator is set up, protected exports are
 * disabled.
 */
final class DataDownloadApproval
{
    public const string CODE_FIELD = 'owner_code';

    public const string EXPORTER_ROLE = 'Admin';

    public static function canExport(): bool
    {
        return Filament::auth()->user()?->hasRole(self::EXPORTER_ROLE) === true;
    }

    /**
     * An export or download of data other than leads / customers /
     * assigned leads: Admin only, without the owner's code. Replaces any
     * visible() set on the action before.
     *
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function adminOnly(Action $action): Action
    {
        return $action->visible(fn (): bool => self::canExport());
    }

    public static function codeField(): OneTimeCodeInput
    {
        return OneTimeCodeInput::make(self::CODE_FIELD)
            ->label('Owner\'s authenticator code')
            ->belowContent('Ask the owner for the 6-digit code their authenticator app shows right now. Each code approves one download.')
            ->required()
            ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                self::validateCode((string) $value, $fail);
            });
    }

    /**
     * @param  Closure(): array<string, mixed>|null  $describeScope  extra details for the activity log (date range, filters)
     */
    public static function protect(ExportAction|ExportBulkAction $action, string $dataset, ?Closure $describeScope = null): ExportAction|ExportBulkAction
    {
        return $action
            ->visible(fn (): bool => self::canExport())
            ->disabled(fn (): bool => ! app(DataDownloadAuthenticatorService::class)->isConfigured())
            ->tooltip(fn (): ?string => app(DataDownloadAuthenticatorService::class)->isConfigured()
                ? null
                : 'The owner must set up the download authenticator first (Data Download page).')
            ->mutateDataUsing(function (array $data, Action $action): array {
                $service = app(DataDownloadAuthenticatorService::class);

                if (! self::canExport()) {
                    Notification::make()->title('Only an Admin can export data.')->danger()->send();

                    $action->halt();
                }

                if (! $service->consume((string) ($data[self::CODE_FIELD] ?? ''))) {
                    Notification::make()
                        ->title('Code already used or expired')
                        ->body('Ask the owner for the next code and try again.')
                        ->danger()
                        ->send();

                    $action->halt();
                }

                $service->clearFailedAttempts(self::user());

                unset($data[self::CODE_FIELD]);

                return $data;
            })
            ->after(function (array $data, Action $action) use ($dataset, $describeScope): void {
                $columns = collect($data['columnMap'] ?? [])
                    ->filter(fn (mixed $column): bool => (bool) data_get($column, 'isEnabled'))
                    ->keys()
                    ->values()
                    ->all();

                app(DataDownloadAuthenticatorService::class)->log(
                    "Downloaded {$dataset} with the owner's approval",
                    self::user(),
                    [
                        'dataset' => $dataset,
                        'source' => $action instanceof ExportBulkAction ? 'selected records' : class_basename($action->getLivewire()),
                        ...($describeScope ? $describeScope() : []),
                        'columns' => $columns,
                    ],
                );
            });
    }

    private static function validateCode(#[SensitiveParameter] string $code, Closure $fail): void
    {
        $service = app(DataDownloadAuthenticatorService::class);
        $user = self::user();

        if (! self::canExport()) {
            $fail('Only an Admin can export data.');

            return;
        }

        if (! $service->isConfigured()) {
            $fail('The owner has not set up the download authenticator yet.');

            return;
        }

        if ($service->isLockedOut($user)) {
            $fail("Too many wrong codes. Try again in {$service->lockoutMinutesRemaining($user)} minute(s).");

            return;
        }

        if ($service->accepts($code)) {
            return;
        }

        $service->recordFailedAttempt($user);

        $fail('That code is wrong, expired or already used. Ask the owner for the current code.');
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
