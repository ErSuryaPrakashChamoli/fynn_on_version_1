<?php

namespace App\Filament\Pages;

use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Actions\OwnerAuthenticatorActions;
use App\Filament\Exports\CustomerExporter;
use App\Filament\Exports\LeadExporter;
use App\Models\Customer;
use App\Models\DataDownloadAuthenticator;
use App\Models\Lead;
use App\Services\DataDownloadAuthenticatorService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin-only downloads of every lead and every customer file added in a
 * chosen date range, with every field available (the admin can untick
 * any in the export pop-up).
 *
 * Each download needs the owner's approval: the 6-digit code from the
 * authenticator app the owner set up once, here or on My Profile
 * (OwnerAuthenticatorActions, DataDownloadAuthenticatorService,
 * DataDownloadApproval). The owner can
 * move it to a new phone with a code from the current one; a lost phone is
 * cleared on the server with `php artisan data-download:reset-authenticator`.
 *
 * Files are built by Filament's queued export and offered through the
 * notification bell. Setup, replacement and every download are listed
 * under "Recent activity".
 */
class DataDownload extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static ?string $navigationLabel = 'Data Download';

    protected static ?string $title = 'Data Download';

    protected static ?string $slug = 'data-download';

    protected string $view = 'filament.pages.data-download';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('Admin') === true;
    }

    public function mount(): void
    {
        $this->getSchema('form')?->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'until' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        DatePicker::make('from')
                            ->label('Added from')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->maxDate(fn (Get $get): mixed => $get('until') ?: now())
                            ->live(),

                        DatePicker::make('until')
                            ->label('Added up to')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->minDate(fn (Get $get): mixed => $get('from'))
                            ->maxDate(now())
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function downloadLeadsAction(): Action
    {
        return DataDownloadApproval::protect(
            ExportAction::make('downloadLeads')
                ->label('Download leads')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->exporter(LeadExporter::class)
                ->modalHeading('Download leads')
                ->modalDescription(fn (): string => $this->rangeSummary($this->leadCount(), 'lead'))
                ->columnMappingColumns(2)
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDateRange($query))
                ->fileName(fn (Export $export): string => "leads-{$this->fileNameRange()}-{$export->getKey()}"),
            'leads',
            fn (): array => $this->rangeForLog(),
        );
    }

    public function downloadCustomersAction(): Action
    {
        return DataDownloadApproval::protect(
            ExportAction::make('downloadCustomers')
                ->label('Download customers')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->exporter(CustomerExporter::class)
                ->modalHeading('Download customers')
                ->modalDescription(fn (): string => $this->rangeSummary($this->customerCount(), 'customer'))
                ->columnMappingColumns(2)
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDateRange($query))
                ->fileName(fn (Export $export): string => "customers-{$this->fileNameRange()}-{$export->getKey()}"),
            'customers',
            fn (): array => $this->rangeForLog(),
        );
    }

    public function setUpAuthenticatorAction(): Action
    {
        return OwnerAuthenticatorActions::setUp();
    }

    public function replaceAuthenticatorAction(): Action
    {
        return OwnerAuthenticatorActions::replace();
    }

    public function authenticator(): ?DataDownloadAuthenticator
    {
        return $this->authenticators()->current()?->loadMissing('configuredBy');
    }

    public function leadCount(): int
    {
        return $this->applyDateRange(Lead::query())->count();
    }

    public function customerCount(): int
    {
        return $this->applyDateRange(Customer::query())->count();
    }

    /**
     * @return Collection<int, Activity>
     */
    public function recentActivity(): Collection
    {
        return Activity::query()
            ->inLog(DataDownloadAuthenticatorService::LOG_NAME)
            ->with('causer')
            ->latest('id')
            ->limit(15)
            ->get();
    }

    /**
     * The chosen range as whole days; a reversed range is swapped and an
     * empty side is open-ended.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function dateRange(): array
    {
        $from = filled($this->data['from'] ?? null) ? CarbonImmutable::parse($this->data['from'])->startOfDay() : null;
        $until = filled($this->data['until'] ?? null) ? CarbonImmutable::parse($this->data['until'])->endOfDay() : null;

        if ($from !== null && $until !== null && $from->greaterThan($until)) {
            [$from, $until] = [$until->startOfDay(), $from->endOfDay()];
        }

        return [$from, $until];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyDateRange(Builder $query): Builder
    {
        [$from, $until] = $this->dateRange();
        $column = $query->qualifyColumn('created_at');

        return $query
            ->when($from, fn (Builder $query): Builder => $query->where($column, '>=', $from))
            ->when($until, fn (Builder $query): Builder => $query->where($column, '<=', $until));
    }

    public function rangeLabel(): string
    {
        [$from, $until] = $this->dateRange();

        return match (true) {
            $from !== null && $until !== null => $from->format('d M Y').' – '.$until->format('d M Y'),
            $from !== null => 'from '.$from->format('d M Y'),
            $until !== null => 'up to '.$until->format('d M Y'),
            default => 'all dates',
        };
    }

    private function rangeSummary(int $count, string $noun): string
    {
        return number_format($count).' '.str($noun)->plural($count).' added '.$this->rangeLabel().'. Untick any field you do not need.';
    }

    /**
     * @return array{from: string|null, until: string|null}
     */
    private function rangeForLog(): array
    {
        [$from, $until] = $this->dateRange();

        return [
            'from' => $from?->toDateString(),
            'until' => $until?->toDateString(),
        ];
    }

    private function fileNameRange(): string
    {
        [$from, $until] = $this->dateRange();

        return ($from?->format('Ymd') ?? 'start').'-'.($until?->format('Ymd') ?? 'now');
    }

    private function authenticators(): DataDownloadAuthenticatorService
    {
        return app(DataDownloadAuthenticatorService::class);
    }
}
