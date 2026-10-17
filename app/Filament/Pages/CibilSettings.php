<?php

namespace App\Filament\Pages;

use App\Enums\CreditBureau;
use App\Models\CibilSetting;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The Admin's switches for the CIBIL module (App\Models\CibilSetting): the
 * master switch and one per request type, and the reuse/refresh policy.
 * Both bureaus (CIBIL, Experian) are always offered to users. Below the form, whether each bureau's
 * provider connection is configured — read from config('cibil'), never
 * showing a token. Every save is written to the
 * CIBIL audit log.
 */
class CibilSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'CIBIL Settings';

    protected static ?string $title = 'CIBIL Settings';

    protected static ?string $slug = 'cibil-settings';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.cibil-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(CibilSetting::current()->only([
            'requests_enabled',
            'score_enabled',
            'full_report_enabled',
            'pdf_enabled',
            'fresh_requests_enabled',
            'reuse_window_days',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requests')
                    ->description('Nothing is sent to the bureau while the master switch is off. Every request that does go out is billed by the provider.')
                    ->schema([
                        Toggle::make('requests_enabled')
                            ->label('Send requests to the bureaus')
                            ->live(),

                        Toggle::make('score_enabled')
                            ->label('Score checks')
                            ->disabled(fn (Get $get): bool => ! $get('requests_enabled')),

                        Toggle::make('full_report_enabled')
                            ->label('Full reports')
                            ->disabled(fn (Get $get): bool => ! $get('requests_enabled')),

                        Toggle::make('pdf_enabled')
                            ->label('PDF reports')
                            ->disabled(fn (Get $get): bool => ! $get('requests_enabled')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Reuse and fresh pulls')
                    ->description('A request for a lead or customer file is answered from a report the file already has whenever that report covers it, so the bureau is not paid twice for the same answer.')
                    ->schema([
                        TextInput::make('reuse_window_days')
                            ->label('Reuse a report for (days)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->placeholder('No limit')
                            ->helperText('Leave empty to reuse a file\'s report until someone with the "Fresh pulls" permission asks for a new one. With a number, an older report no longer counts and a normal request goes to the bureau.'),

                        Toggle::make('fresh_requests_enabled')
                            ->label('Allow fresh pulls over a reusable report')
                            ->helperText('Only for logins with the "Fresh pulls" permission, and always with a reason.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = CibilSetting::current();

        $settings->fill([
            'requests_enabled' => (bool) ($data['requests_enabled'] ?? false),
            'score_enabled' => (bool) ($data['score_enabled'] ?? $settings->score_enabled),
            'full_report_enabled' => (bool) ($data['full_report_enabled'] ?? $settings->full_report_enabled),
            'pdf_enabled' => (bool) ($data['pdf_enabled'] ?? $settings->pdf_enabled),
            'fresh_requests_enabled' => (bool) ($data['fresh_requests_enabled'] ?? false),
            'reuse_window_days' => filled($data['reuse_window_days'] ?? null) ? (int) $data['reuse_window_days'] : null,
            'updated_by' => Filament::auth()->id(),
        ]);

        $changes = collect($settings->getDirty())->except(['updated_by', 'updated_at'])->all();

        $settings->save();

        activity(CibilReportService::LOG_NAME)
            ->causedBy(Filament::auth()->user())
            ->event('updated')
            ->withProperties(['changes' => $changes])
            ->log('CIBIL settings updated');

        Notification::make()
            ->title('CIBIL settings saved')
            ->success()
            ->send();
    }

    /**
     * Whether the provider connection is configured — never its secrets.
     *
     * @return array{driver: string, live: bool, bureaus: array<string, array{label: string, token_configured: bool, base_url: string|null, base_url_valid: bool, endpoints: array<string, string|null>, timeout: int}>, pdf_disk: string, pdf_max_mb: float}
     */
    public function connectionStatus(): array
    {
        return [
            'driver' => (string) config('cibil.driver'),
            'live' => config('cibil.driver') === 'surepass',
            'bureaus' => collect(CreditBureau::cases())->mapWithKeys(function (CreditBureau $bureau): array {
                $config = $bureau->config();
                $baseUrl = $config['base_url'] ?? null;

                return [$bureau->value => [
                    'label' => $bureau->label(),
                    'token_configured' => filled($config['token'] ?? null),
                    'base_url' => is_string($baseUrl) ? $baseUrl : null,
                    'base_url_valid' => is_string($baseUrl) && str_starts_with(strtolower($baseUrl), 'https://'),
                    'endpoints' => (array) ($config['endpoints'] ?? []),
                    'timeout' => (int) ($config['timeout'] ?? 0),
                ]];
            })->all(),
            'pdf_disk' => (string) config('cibil.pdf.disk'),
            'pdf_max_mb' => round(((int) config('cibil.pdf.max_bytes')) / 1048576, 1),
        ];
    }

    public static function canAccess(): bool
    {
        return CibilAccess::canManage(Filament::auth()->user());
    }
}
