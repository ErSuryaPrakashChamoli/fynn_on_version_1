<?php

namespace App\Filament\Actions;

use App\Filament\Pages\DataDownload;
use App\Models\User;
use App\Services\DataDownloadAuthenticatorService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use SensitiveParameter;

/**
 * Setting up the owner authenticator that approves data downloads
 * (DataDownloadAuthenticatorService), and moving it to a new phone. Offered
 * on the Data Download page and on My Profile, both to the Admin only
 * (DataDownload::canAccess()).
 *
 * The new secret is generated on mount and travels only in the action's
 * encrypted arguments; it is shown once, as a QR code and a typed key.
 */
final class OwnerAuthenticatorActions
{
    public static function setUp(): Action
    {
        return Action::make('setUpAuthenticator')
            ->label('Set up owner authenticator')
            ->icon(Heroicon::OutlinedQrCode)
            ->visible(fn (): bool => DataDownload::canAccess() && ! self::service()->isConfigured())
            ->mountUsing(function (Schema $schema, HasActions $livewire): void {
                $schema->fill();

                $livewire->mergeMountedActionArguments([
                    'encrypted' => encrypt(['secret' => self::service()->generateSecret()]),
                ]);
            })
            ->modalWidth(Width::Large)
            ->closeModalByClickingAway(false)
            ->modalIcon(Heroicon::OutlinedQrCode)
            ->modalHeading('Set up the owner authenticator')
            ->modalDescription('Do this once, with the owner\'s phone. From then on every leads or customers download needs the code this app shows.')
            ->schema(fn (Action $action): array => [
                TextInput::make('owner_name')
                    ->label('Owner\'s name')
                    ->required()
                    ->maxLength(255),
                ...self::enrolmentComponents($action),
            ])
            ->modalSubmitActionLabel('Finish setup')
            ->action(function (array $data, Action $action): void {
                try {
                    self::service()->setUp($data['owner_name'], self::secretFrom($action), $data['code'], self::user());
                } catch (RuntimeException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                self::service()->clearFailedAttempts(self::user());

                Notification::make()
                    ->title('Owner authenticator set up')
                    ->body('Leads and customers downloads now need a code from this authenticator.')
                    ->success()
                    ->send();
            });
    }

    public static function replace(): Action
    {
        return Action::make('replaceAuthenticator')
            ->label('Move to a new phone')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->visible(fn (): bool => DataDownload::canAccess() && self::service()->isConfigured())
            ->mountUsing(function (Schema $schema, HasActions $livewire): void {
                $schema->fill(['owner_name' => self::service()->current()?->owner_name]);

                $livewire->mergeMountedActionArguments([
                    'encrypted' => encrypt(['secret' => self::service()->generateSecret()]),
                ]);
            })
            ->modalWidth(Width::Large)
            ->closeModalByClickingAway(false)
            ->modalIcon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->modalHeading('Move the owner authenticator to a new phone')
            ->modalDescription('Needs a code from the authenticator set up now, so only its holder can hand approvals over. Codes from the old one stop working.')
            ->schema(fn (Action $action): array => [
                DataDownloadApproval::codeField()
                    ->label('Code from the current authenticator')
                    ->belowContent('The 6-digit code the authenticator set up now shows.'),
                TextInput::make('owner_name')
                    ->label('Owner\'s name')
                    ->required()
                    ->maxLength(255),
                ...self::enrolmentComponents($action),
            ])
            ->modalSubmitActionLabel('Move to new phone')
            ->action(function (array $data, Action $action): void {
                try {
                    self::service()->replace(
                        $data[DataDownloadApproval::CODE_FIELD],
                        $data['owner_name'],
                        self::secretFrom($action),
                        $data['code'],
                        self::user(),
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                self::service()->clearFailedAttempts(self::user());

                Notification::make()
                    ->title('Owner authenticator moved')
                    ->body('Downloads now need a code from the new phone.')
                    ->success()
                    ->send();
            });
    }

    /**
     * QR code, typed key and confirmation code for a new authenticator.
     *
     * @return array<Component>
     */
    private static function enrolmentComponents(Action $action): array
    {
        return [
            Text::make('Scan this QR code with Google Authenticator or Microsoft Authenticator on the owner\'s phone:')
                ->color('neutral'),
            Image::make(
                url: fn (): string => self::service()->qrCodeDataUri(self::secretFrom($action)),
                alt: 'QR code to scan with an authenticator app',
            )
                ->imageHeight('12rem')
                ->alignCenter(),
            Flex::make([
                Text::make('Or type this key into the app:')
                    ->color('neutral')
                    ->grow(false),
                Text::make(fn (): string => self::secretFrom($action))
                    ->fontFamily(FontFamily::Mono)
                    ->color('neutral')
                    ->copyable()
                    ->grow(false),
            ])->from('sm'),
            OneTimeCodeInput::make('code')
                ->label('Code shown in the app')
                ->belowContent('Enter the 6-digit code the app now shows, to confirm it is set up.')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($action): void {
                    $service = self::service();
                    $user = self::user();

                    if ($service->isLockedOut($user)) {
                        $fail("Too many wrong codes. Try again in {$service->lockoutMinutesRemaining($user)} minute(s).");

                        return;
                    }

                    if ($service->codeMatchesSecret(self::secretFrom($action), (string) $value)) {
                        return;
                    }

                    $service->recordFailedAttempt($user);

                    $fail('That code does not match. Scan the QR code again and enter the code the app shows now.');
                }),
        ];
    }

    private static function secretFrom(Action $action): string
    {
        return decrypt($action->getArguments()['encrypted'])['secret'];
    }

    private static function service(): DataDownloadAuthenticatorService
    {
        return app(DataDownloadAuthenticatorService::class);
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
