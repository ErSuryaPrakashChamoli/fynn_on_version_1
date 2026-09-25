<?php

namespace App\Services;

use App\Enums\JourneyModule;
use App\Enums\NotificationCategory;
use App\Enums\OtherBankRemarkStage;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportRemark;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Single authority for the Other Bank Support role.
 *
 * A customer file becomes an "other bank" file once it is eligible and its
 * bank_eligible_for is anything other than the in-house BFL products. Every
 * Other Bank Support user sees and may work every such file, whoever owns
 * it; ownership (assign_to / employee_id) and the LMS's own figures are
 * never touched.
 *
 * Achievement for this team is the other-bank slice of the month's business,
 * computed with the LMS's own count-achievement formula. It is credited in
 * full to every active support user (whole-team attribution, decided with
 * the user) and is never added to any LMS total.
 */
class OtherBankSupportService
{
    public const ROLE = 'Other Bank Support';

    /**
     * Banks handled in-house. A file eligible for any other bank belongs to
     * the Other Bank Support pool.
     *
     * @var array<int, string>
     */
    public const IN_HOUSE_BANKS = ['BFL Prime', 'BFL Growth', 'BFL RSL', 'BFL SOL'];

    public function __construct(private AchievementCalculatorService $calculator) {}

    public static function isSupportUser(?User $user): bool
    {
        return $user !== null && $user->hasRole(self::ROLE);
    }

    /**
     * A support user whose customer access is limited to other-bank files.
     * An Admin who also holds the role keeps seeing everything.
     */
    public static function isScopedSupportUser(?User $user): bool
    {
        return self::isSupportUser($user) && ! $user->hasRole('Admin');
    }

    /**
     * Restricts a customers query to other-bank files. Bank names are
     * compared case/whitespace/hyphen-insensitively, the same normalisation
     * AchievementCalculatorService applies, because the importer and OCR can
     * write variants like "bfl-prime".
     */
    public static function applyOtherBankScope(Builder $query): Builder
    {
        $inHouse = self::canonicalInHouseBanks();

        return $query
            ->where('customers.eligibility_status', 'eligible')
            ->whereNotNull('customers.bank_eligible_for')
            ->where('customers.bank_eligible_for', '!=', '')
            ->whereRaw(
                "UPPER(TRIM(REPLACE(customers.bank_eligible_for, '-', ' '))) NOT IN (".implode(', ', array_fill(0, count($inHouse), '?')).')',
                $inHouse,
            );
    }

    public static function isOtherBankCase(Customer $customer): bool
    {
        return $customer->eligibility_status === 'eligible'
            && filled($customer->bank_eligible_for)
            && ! in_array(self::canonicalBankName((string) $customer->bank_eligible_for), self::canonicalInHouseBanks(), true);
    }

    public static function canWorkOn(?User $user, Customer $customer): bool
    {
        return self::isSupportUser($user) && self::isOtherBankCase($customer);
    }

    /*
    |--------------------------------------------------------------------------
    | Remarks
    |--------------------------------------------------------------------------
    */

    /**
     * @throws AuthorizationException when the user may not work this file
     */
    public function addRemark(User $user, Customer $customer, OtherBankRemarkStage $stage, string $remark): OtherBankSupportRemark
    {
        if (! self::canWorkOn($user, $customer)) {
            throw new AuthorizationException('Only Other Bank Support can add remarks, and only on other-bank files.');
        }

        $created = OtherBankSupportRemark::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'stage' => $stage,
            'remark' => trim($remark),
        ]);

        $this->notifyOwnerChain($customer, $created, $user);

        return $created;
    }

    /**
     * Remarks grouped by step, in journey order, newest first within a step.
     *
     * @return array<string, array{label: string, remarks: Collection<int, OtherBankSupportRemark>}>
     */
    public function remarksByStage(Customer $customer): array
    {
        $remarks = OtherBankSupportRemark::query()
            ->where('customer_id', $customer->id)
            ->with('user')
            ->latest()
            ->latest('id')
            ->get()
            ->groupBy(fn (OtherBankSupportRemark $remark): string => $remark->stage->value);

        $grouped = [];

        foreach (OtherBankRemarkStage::cases() as $stage) {
            $grouped[$stage->value] = [
                'label' => $stage->label(),
                'remarks' => $remarks->get($stage->value, collect()),
            ];
        }

        return $grouped;
    }

    /*
    |--------------------------------------------------------------------------
    | Business, target and incentive
    |--------------------------------------------------------------------------
    */

    /**
     * The month's whole-LMS business next to the other-bank slice of it. The
     * LMS figure is exactly what AchievementCalculatorService counts for the
     * company — the other-bank figure is a subset, never an addition.
     *
     * @return array{month: Carbon, total_achievement: float, total_disbursed: float, total_files: int, other_bank_achievement: float, other_bank_disbursed: float, other_bank_files: int, share_percentage: float}
     */
    public function monthlySummary(Carbon $month): array
    {
        $totals = $this->calculator->computeAchievementTotals($this->disbursedIn($month));
        $otherBank = $this->calculator->computeAchievementTotals(self::applyOtherBankScope($this->disbursedIn($month)));

        return [
            'month' => $month->copy()->startOfMonth(),
            'total_achievement' => $totals['count_achievement'],
            'total_disbursed' => $totals['actual'],
            'total_files' => $this->disbursedIn($month)->count(),
            'other_bank_achievement' => $otherBank['count_achievement'],
            'other_bank_disbursed' => $otherBank['actual'],
            'other_bank_files' => self::applyOtherBankScope($this->disbursedIn($month))->count(),
            'share_percentage' => $this->calculator->percentageFromAmounts($otherBank['count_achievement'], $totals['count_achievement']),
        ];
    }

    public function targetFor(User $user, Carbon $month): float
    {
        return (float) (OtherBankSupportTarget::query()
            ->where('user_id', $user->id)
            ->forMonth($month)
            ->value('target_amount') ?? 0);
    }

    /**
     * The slab ladder in force for a month: the most recent set whose
     * effective month is on or before it.
     *
     * @return Collection<int, OtherBankIncentiveSlab>
     */
    public function slabsFor(Carbon $month): Collection
    {
        $effectiveMonth = OtherBankIncentiveSlab::query()
            ->whereDate('effective_month', '<=', $month->copy()->startOfMonth()->toDateString())
            ->max('effective_month');

        if ($effectiveMonth === null) {
            return collect();
        }

        return OtherBankIncentiveSlab::query()
            ->forMonth(Carbon::parse($effectiveMonth))
            ->orderBy('min_achievement')
            ->get();
    }

    /**
     * @return array{incentive: float, slab: ?OtherBankIncentiveSlab, next_slab: ?OtherBankIncentiveSlab, remaining_to_next: float}
     */
    public function incentiveFor(float $achievement, Carbon $month): array
    {
        $slabs = $this->slabsFor($month);

        $slab = $slabs->last(fn (OtherBankIncentiveSlab $slab): bool => $achievement >= $slab->min_achievement);
        $next = $slabs->first(fn (OtherBankIncentiveSlab $slab): bool => $achievement < $slab->min_achievement);

        return [
            'incentive' => $slab?->payoutFor(max($achievement, 0)) ?? 0.0,
            'slab' => $slab,
            'next_slab' => $next,
            'remaining_to_next' => $next ? max($next->min_achievement - $achievement, 0) : 0.0,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, target: float, achievement: float, percentage: float, incentive: float, slab: ?OtherBankIncentiveSlab, next_slab: ?OtherBankIncentiveSlab, remaining_to_next: float}
     */
    public function performanceFor(User $user, Carbon $month, ?array $summary = null): array
    {
        $summary ??= $this->monthlySummary($month);
        $achievement = $summary['other_bank_achievement'];
        $target = $this->targetFor($user, $month);

        return [
            'summary' => $summary,
            'target' => $target,
            'achievement' => $achievement,
            'percentage' => $this->calculator->percentageFromAmounts($achievement, $target),
            ...$this->incentiveFor($achievement, $month),
        ];
    }

    /**
     * Active users holding the role, for the Admin's team table and target form.
     *
     * @return Collection<int, User>
     */
    public function supportUsers(): Collection
    {
        return User::role(self::ROLE)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function disbursedIn(Carbon $month): Builder
    {
        return Customer::query()->whereBetween('customers.disbursal_date', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        ]);
    }

    /**
     * Tells the file's owner, everyone above them and anyone standing in for
     * them that a remark landed.
     * Best-effort, like the journey audit: a notification failure must never
     * lose the remark.
     */
    private function notifyOwnerChain(Customer $customer, OtherBankSupportRemark $remark, User $author): void
    {
        try {
            $access = app(CustomerJourneyAccessService::class);

            $employeeIds = $access->responsibleEmployeeChain($customer)
                ->push($customer->employee_id)
                ->merge($access->activeBackupIdsFor($customer, JourneyModule::forCustomer($customer)))
                ->filter()
                ->unique();

            $recipients = User::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereKeyNot($author->id)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::make()
                ->title('Other Bank Support remark')
                ->body("{$author->name} on {$customer->customer_name} ({$customer->application_no}) — {$remark->stage->label()}: {$remark->remark}")
                ->info()
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->actions([
                    Action::make('view')
                        ->label('View file')
                        ->url(CustomerResource::getUrl('view', ['record' => $customer]))
                        ->markAsRead(),
                ])
                ->viewData(NotificationCategory::OtherBankSupport->viewData())
                ->sendToDatabase($recipients);
        } catch (Throwable) {
            // A failed notification must never block the remark itself.
        }
    }

    /**
     * @return array<int, string>
     */
    private static function canonicalInHouseBanks(): array
    {
        return array_map(self::canonicalBankName(...), self::IN_HOUSE_BANKS);
    }

    private static function canonicalBankName(string $bankName): string
    {
        return strtoupper(trim(str_replace('-', ' ', $bankName)));
    }
}
