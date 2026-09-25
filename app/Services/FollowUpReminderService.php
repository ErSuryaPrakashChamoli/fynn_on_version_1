<?php

namespace App\Services;

use App\Enums\JourneyModule;
use App\Enums\NotificationCategory;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\CustomerAssignment;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Support\Demo\DemoContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns due follow-ups into bell notifications (which the reminder pop-up
 * then puts in front of the user) and closes them out again.
 *
 * Every outcome — done, dropped, rescheduled — is written the way the rest
 * of the app writes a follow-up interaction: as a NEW follow_ups row on the
 * same prospect (or, for a raw lead, by updating the lead, which logs the
 * row itself). The prospect's log therefore stays one continuous history
 * whether the follow-up was handled from the pop-up, the bell or a listing.
 */
class FollowUpReminderService
{
    /** A reminder goes out this many minutes before the follow-up is due. */
    public const LEAD_MINUTES = 10;

    /**
     * Follow-ups overdue by longer than this are not reminded one by one —
     * on the first run that would bury people under their whole backlog.
     * They are still listed in the pop-up's overdue count for bulk handling.
     */
    public const LOOKBACK_HOURS = 24;

    public const STATUS_DROPPED = 'Dropped';

    /**
     * Sends a reminder for every current follow-up coming due and not yet
     * reminded. Returns how many follow-ups were reminded.
     */
    public function sendDueReminders(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        FollowUp::query()
            ->latestPerSubject()
            ->scheduled()
            ->whereNull('reminded_at')
            ->whereBetween('next_follow_up_date', [
                $now->copy()->subHours(self::LOOKBACK_HOURS),
                $now->copy()->addMinutes(self::LEAD_MINUTES),
            ])
            ->with(['customer', 'aiCustomerRecord', 'lead'])
            ->chunkById(200, function (Collection $followUps) use (&$sent): void {
                foreach ($followUps as $followUp) {
                    $this->remind($followUp);
                    $sent++;
                }
            });

        return $sent;
    }

    public function remind(FollowUp $followUp): void
    {
        $recipients = $this->recipientsFor($followUp);

        if ($recipients->isNotEmpty()) {
            $due = $followUp->next_follow_up_date;

            Notification::make()
                ->title('Follow-up due: '.$followUp->display_name)
                ->body(collect([
                    $followUp->status,
                    $due ? 'Due '.$due->format('d M Y, h:i A') : null,
                    filled($followUp->remarks) ? 'Last remark: '.str($followUp->remarks)->limit(80) : null,
                ])->filter()->implode(' · '))
                ->icon(NotificationCategory::FollowUp->icon())
                ->iconColor($due && $due->isPast() ? 'danger' : 'warning')
                ->viewData([
                    ...NotificationCategory::FollowUp->viewData(),
                    'follow_up_id' => $followUp->id,
                ])
                ->actions([
                    Action::make('open')
                        ->label('Open')
                        ->url($this->subjectUrl($followUp))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        }

        $followUp->forceFill(['reminded_at' => now()])->saveQuietly();
    }

    /**
     * The owner of the prospect today (not whoever happened to log the last
     * follow-up), plus anyone covering for the owner through Team Continuity
     * or an emergency takeover.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(FollowUp $followUp): Collection
    {
        $employeeIds = collect([$this->ownerEmployeeId($followUp)]);

        if ($followUp->customer) {
            try {
                $employeeIds = $employeeIds->merge(
                    app(CustomerJourneyAccessService::class)->activeBackupIdsFor($followUp->customer, JourneyModule::CustomerFollowUp)
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $employeeIds = $employeeIds->filter()->unique();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('employee_id', $employeeIds)->get();
    }

    public function ownerEmployeeId(FollowUp $followUp): ?int
    {
        if ($followUp->lead) {
            return $followUp->lead->employee_id;
        }

        $assignment = $this->assignmentFor($followUp);

        if ($assignment) {
            return $assignment->employee_id;
        }

        return $followUp->customer?->assign_to ?? $followUp->employee_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Outcomes
    |--------------------------------------------------------------------------
    */

    /** The follow-up happened; it comes off every calendar. */
    public function complete(FollowUp $followUp, User $user, string $remarks): FollowUp
    {
        return $this->logOutcome($followUp, $user, [
            'status' => $followUp->status,
            'remarks' => $remarks,
            'next_follow_up_date' => null,
        ]);
    }

    /** The prospect is no longer worth chasing. */
    public function drop(FollowUp $followUp, User $user, ?string $remarks = null): FollowUp
    {
        return $this->logOutcome($followUp, $user, [
            'status' => self::STATUS_DROPPED,
            'remarks' => filled($remarks) ? $remarks : 'Follow-up dropped',
            'next_follow_up_date' => null,
        ]);
    }

    public function reschedule(FollowUp $followUp, User $user, Carbon $when, ?string $remarks = null): FollowUp
    {
        return $this->logOutcome($followUp, $user, [
            'status' => $followUp->status,
            'remarks' => filled($remarks) ? $remarks : 'Rescheduled to '.$when->format('d M Y, h:i A'),
            'next_follow_up_date' => $when,
        ]);
    }

    /**
     * Drops the current follow-up of every prospect in the set, skipping any
     * row that has since been superseded. Returns how many were dropped.
     *
     * @param  iterable<int, FollowUp>  $followUps
     */
    public function dropMany(iterable $followUps, User $user, ?string $remarks = null): int
    {
        $dropped = 0;

        foreach ($followUps as $followUp) {
            $current = $this->currentFor($followUp);

            if (! $current || $current->status === self::STATUS_DROPPED) {
                continue;
            }

            $this->drop($current, $user, $remarks);
            $dropped++;
        }

        return $dropped;
    }

    /**
     * The newest follow-up of the same prospect — the only one whose date
     * still counts (see FollowUp::scopeLatestPerSubject()).
     */
    public function currentFor(FollowUp $followUp): ?FollowUp
    {
        return FollowUp::query()->forSameSubjectAs($followUp)->latest('id')->first();
    }

    /**
     * The current follow-up of each assigned lead, for bulk actions on the
     * Assigned Leads listing.
     *
     * @param  iterable<int, CustomerAssignment>  $assignments
     * @return Collection<int, FollowUp>
     */
    public function currentForAssignments(iterable $assignments): Collection
    {
        return collect($assignments)
            ->map(fn (CustomerAssignment $assignment): ?FollowUp => $assignment->latestFollowUp())
            ->filter()
            ->values();
    }

    /**
     * Follow-ups owned by (or covered by) this user that are already due and
     * still open — the backlog the pop-up offers to clear in bulk.
     *
     * @return Builder<FollowUp>
     */
    public function overdueQueryFor(User $user): Builder
    {
        $employeeId = $user->employee_id;

        return FollowUp::query()
            ->latestPerSubject()
            ->scheduled()
            ->where('next_follow_up_date', '<', now())
            ->where(function (Builder $query) use ($employeeId): void {
                $query->where('employee_id', $employeeId)
                    ->orWhereHas('lead', fn (Builder $lead) => $lead->where('employee_id', $employeeId))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('assign_to', $employeeId));
            });
    }

    /**
     * @param  array{status: ?string, remarks: string, next_follow_up_date: ?Carbon}  $outcome
     */
    private function logOutcome(FollowUp $followUp, User $user, array $outcome): FollowUp
    {
        return DB::transaction(function () use ($followUp, $user, $outcome): FollowUp {
            if ($followUp->lead) {
                // A raw lead mirrors its follow-up columns; updating it logs
                // the new follow_ups row itself (Lead::booted()).
                $followUp->lead->update([
                    'status' => $outcome['status'] ?? $followUp->lead->status,
                    'remarks' => $outcome['remarks'],
                    'next_follow_up_date' => $outcome['next_follow_up_date'],
                ]);

                $logged = $followUp->lead->followUps()->latest('id')->first();
            } else {
                $logged = FollowUp::create([
                    'customer_id' => $followUp->customer_id,
                    'ai_customer_record_id' => $followUp->ai_customer_record_id,
                    'employee_id' => $user->employee_id ?? $followUp->employee_id,
                    'follow_up_type' => $followUp->follow_up_type,
                    'status' => $outcome['status'] ?? $followUp->status,
                    'remarks' => $outcome['remarks'],
                    'next_follow_up_date' => $outcome['next_follow_up_date'],
                    'bank_id' => $followUp->bank_id,
                ]);
            }

            $this->resolveReminders($followUp, $outcome['remarks']);

            return $logged ?? $followUp;
        });
    }

    /**
     * Marks every still-open reminder about this prospect as handled, so the
     * pop-up and bell stop asking about a follow-up that has moved on.
     */
    private function resolveReminders(FollowUp $followUp, string $remarks): void
    {
        $subjectIds = FollowUp::query()->forSameSubjectAs($followUp)->pluck('id')->all();

        DatabaseNotification::query()
            ->where('category', NotificationCategory::FollowUp->value)
            ->whereNull('resolved_at')
            ->whereIn('data->viewData->follow_up_id', $subjectIds)
            ->get()
            ->each(fn (DatabaseNotification $notification) => $notification->forceFill([
                'read_at' => $notification->read_at ?? now(),
                'resolution' => 'handled',
                'resolution_remarks' => $remarks,
                'resolved_at' => now(),
                'remind_at' => null,
            ])->save());
    }

    private function assignmentFor(FollowUp $followUp): ?CustomerAssignment
    {
        if ($followUp->ai_customer_record_id) {
            return CustomerAssignment::query()->where('ai_customer_record_id', $followUp->ai_customer_record_id)->latest('id')->first();
        }

        if ($followUp->customer_id) {
            return CustomerAssignment::query()->where('customer_id', $followUp->customer_id)->whereNull('converted_at')->latest('id')->first();
        }

        return null;
    }

    private function subjectUrl(FollowUp $followUp): ?string
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? (DemoContext::isActive() ? 'demo' : 'admin');

        try {
            if ($followUp->lead_id) {
                return LeadResource::getUrl('edit', ['record' => $followUp->lead_id], panel: $panel);
            }

            $assignment = $this->assignmentFor($followUp);

            if ($assignment) {
                return AssignedLeadResource::getUrl('edit', ['record' => $assignment], panel: $panel);
            }

            if ($followUp->customer_id) {
                return CustomerResource::getUrl('view', ['record' => $followUp->customer_id], panel: $panel);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }
}
