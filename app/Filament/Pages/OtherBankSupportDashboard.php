<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\OtherBankSupportService;
use App\Support\SelectedMonth;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The month's LMS business next to the other-bank slice of it, plus the
 * support team's targets and incentives. Visible to Other Bank Support and
 * the Admin only; the month follows the panel-wide month selector.
 */
class OtherBankSupportDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Other Bank Support';

    protected static ?string $navigationLabel = 'Other Bank Business';

    protected static ?string $title = 'Other Bank Business';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.other-bank-support-dashboard';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ($user->hasRole('Admin') || OtherBankSupportService::isSupportUser($user));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $service = app(OtherBankSupportService::class);
        $month = SelectedMonth::current();
        $summary = $service->monthlySummary($month);
        $isAdmin = $user->hasRole('Admin');

        return [
            'month' => $month,
            'summary' => $summary,
            'isAdmin' => $isAdmin,
            'myPerformance' => OtherBankSupportService::isSupportUser($user)
                ? $service->performanceFor($user, $month, $summary)
                : null,
            'teamRows' => $isAdmin
                ? $service->supportUsers()->map(fn (User $member): array => [
                    'user' => $member,
                    ...$service->performanceFor($member, $month, $summary),
                ])
                : collect(),
            'slabs' => $service->slabsFor($month),
        ];
    }
}
