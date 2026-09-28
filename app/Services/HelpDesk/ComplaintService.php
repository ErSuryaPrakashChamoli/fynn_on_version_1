<?php

namespace App\Services\HelpDesk;

use App\Enums\ComplaintStatus;
use App\Enums\NotificationCategory;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintComment;
use App\Models\ComplaintPriority;
use App\Models\ComplaintReason;
use App\Models\Employee;
use App\Models\User;
use App\Support\Demo\DemoContext;
use App\Support\HierarchyHelper;
use App\Support\ReportingTree;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every change to a help-desk ticket goes through here, so the row, its
 * thread (complaint_comments) and the bell notifications never disagree.
 *
 * Routing: a Team category puts the ticket in the queue of everyone holding
 * one of its handler roles (handler_role = the first role, the others see
 * it through visibility); a Supervisor category assigns it straight to the
 * boss the raiser picked from their own reporting line. The deadline is
 * the priority's resolve_within_minutes from the moment it is raised, and
 * again from the moment it is reopened.
 */
class ComplaintService
{
    /**
     * Raises a ticket on behalf of $raiser. $data is the validated create
     * form: category_id, reason_id, priority_id, subject, description,
     * attachments and — for a Supervisor category — escalate_to (user id).
     *
     * @param  array<string, mixed>  $data
     */
    public function raise(User $raiser, array $data): Complaint
    {
        $category = ComplaintCategory::query()->findOrFail((int) $data['category_id']);
        $priority = ComplaintPriority::query()->findOrFail((int) $data['priority_id']);

        $assigneeId = null;
        $handlerRole = null;

        if ($category->routesToSupervisor()) {
            $assigneeId = (int) ($data['escalate_to'] ?? 0);

            if (! $this->supervisorOptionsFor($raiser)->has($assigneeId)) {
                throw new AuthorizationException('Pick one of your own supervisors to send this ticket to.');
            }
        } else {
            $handlerRole = $category->handlerRoles()[0] ?? 'Admin';
        }

        $beneficiaryId = filled($data['on_behalf_of'] ?? null) ? (int) $data['on_behalf_of'] : null;

        if ($beneficiaryId === (int) $raiser->getKey()) {
            $beneficiaryId = null;
        }

        if ($beneficiaryId !== null && ! $this->teamMemberOptionsFor($raiser)->has($beneficiaryId)) {
            throw new AuthorizationException('You can only raise a ticket for yourself or for somebody in your own team.');
        }

        $now = now();
        $reason = filled($data['reason_id'] ?? null) ? ComplaintReason::query()->find((int) $data['reason_id']) : null;

        // The form asks for no subject: the reason (or the category) names
        // the ticket, so listings and notifications still read well.
        $subject = trim((string) ($data['subject'] ?? '')) ?: ($reason?->name ?? $category->name);
        $description = trim((string) ($data['description'] ?? '')) ?: null;

        $complaint = DB::transaction(function () use ($raiser, $data, $category, $priority, $assigneeId, $handlerRole, $now, $reason, $subject, $description, $beneficiaryId): Complaint {
            $complaint = Complaint::query()->create([
                'raised_by' => $raiser->getKey(),
                'on_behalf_of' => $beneficiaryId,
                'category_id' => $category->id,
                'reason_id' => $reason?->id,
                'priority_id' => $priority->id,
                'subject' => $subject,
                'description' => $description,
                'attachments' => array_values(array_filter((array) ($data['attachments'] ?? []))),
                'status' => ComplaintStatus::Open,
                'handler_role' => $handlerRole,
                'assigned_to' => $assigneeId,
                'sla_started_at' => $now,
                'due_at' => $now->copy()->addMinutes($priority->resolve_within_minutes),
            ]);

            $this->logEvent($complaint, $raiser, sprintf(
                'Ticket raised%s under %s (%s priority, resolve within %s) and sent to %s.',
                $beneficiaryId ? ' for '.$complaint->beneficiary?->name : '',
                $category->name,
                $priority->name,
                $priority->slaLabel(),
                $assigneeId ? $complaint->assignee?->name : $handlerRole.' team',
            ));

            return $complaint;
        });

        $this->notify(
            $this->handlersOf($complaint),
            'New ticket '.$complaint->ticket_no.': '.$complaint->subject,
            sprintf('%s raised a %s priority %s ticket%s. Resolve by %s.', $raiser->name, $priority->name, $category->name, $beneficiaryId ? ' for '.$complaint->beneficiary?->name : '', $complaint->due_at->format('d M, h:i A')),
            $complaint,
            'warning',
        );

        if ($beneficiaryId !== null) {
            $this->notify([$complaint->beneficiary], 'Ticket '.$complaint->ticket_no.' raised for you', $raiser->name.' raised "'.$complaint->subject.'" on your behalf. Sent to '.$complaint->handlerLabel().'.', $complaint, 'info');
        }

        return $complaint;
    }

    /**
     * Whether the "raising this for someone in my team" choice is offered:
     * the Admin (for anybody) and every seat from Team Leader upward.
     */
    public function canRaiseForOthers(User $user): bool
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        $designation = $user->employee?->designation;

        return $designation !== null
            && Employee::designationRank((int) $designation) >= Employee::designationRank(Employee::DESIGNATION_TEAM_LEADER);
    }

    /**
     * The people $raiser may raise a ticket for: everyone below them in the
     * reporting tree who has an active login. Empty for a caller, so the
     * form never shows the choice to them.
     *
     * @return \Illuminate\Support\Collection<int, string> user id => label
     */
    public function teamMemberOptionsFor(User $raiser): \Illuminate\Support\Collection
    {
        if (! $this->canRaiseForOthers($raiser)) {
            return collect();
        }

        $query = User::query()->where('is_active', true)->whereKeyNot($raiser->getKey());

        if (! $raiser->hasRole('Admin')) {
            $subordinateIds = $this->subordinateEmployeeIds($raiser);

            if ($subordinateIds->isEmpty()) {
                return collect();
            }

            $query->whereIn('employee_id', $subordinateIds);
        }

        return $query
            ->with('employee')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (int) $user->getKey() => $user->name.($user->employee?->emp_id ? ' ('.$user->employee->emp_id.')' : ''),
            ]);
    }

    /**
     * The bosses a user may send a Supervisor-routed ticket to: everyone
     * above them in the reporting tree who is still on the rolls and has a
     * login, nearest first.
     *
     * @return \Illuminate\Support\Collection<int, string> user id => label
     */
    public function supervisorOptionsFor(User $raiser): \Illuminate\Support\Collection
    {
        $employee = $raiser->employee;

        if (! $employee) {
            return collect();
        }

        $tree = ReportingTree::load();
        $ancestorIds = array_values(array_filter(
            $tree->ancestorIds($employee->id),
            fn (int $id): bool => ! $tree->isExited($id),
        ));

        if ($ancestorIds === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('employee_id', $ancestorIds)
            ->where('is_active', true)
            ->with('employee')
            ->get()
            ->keyBy('employee_id');

        return collect($ancestorIds)
            ->filter(fn (int $id): bool => $users->has($id))
            ->mapWithKeys(function (int $id) use ($users): array {
                $user = $users->get($id);
                $designation = Employee::designationOptions()[(int) $user->employee?->designation] ?? null;

                return [(int) $user->getKey() => $user->name.($designation ? " ({$designation})" : '')];
            });
    }

    public function takeUp(Complaint $complaint, User $actor): void
    {
        $this->assertCanHandle($complaint, $actor);
        $this->assertUnresolved($complaint);

        $complaint->update([
            'assigned_to' => $actor->getKey(),
            'status' => ComplaintStatus::InProgress,
            'first_response_at' => $complaint->first_response_at ?? now(),
        ]);

        $this->logEvent($complaint, $actor, $actor->name.' took up the ticket.');
        $this->notify([$complaint->raiser], 'Ticket '.$complaint->ticket_no.' is being worked on', $actor->name.' has taken up your ticket.', $complaint, 'info');
    }

    /** Assigns to a named user — Admin anywhere, a handler within their team. */
    public function assign(Complaint $complaint, User $actor, User $assignee): void
    {
        $this->assertCanHandle($complaint, $actor);
        $this->assertUnresolved($complaint);

        if (! $actor->hasRole('Admin') && ! $this->assignableUsersFor($complaint, $actor)->has($assignee->getKey())) {
            throw new AuthorizationException('You can only assign this ticket inside its handling team.');
        }

        $complaint->update([
            'assigned_to' => $assignee->getKey(),
            'status' => $complaint->status === ComplaintStatus::Open ? ComplaintStatus::InProgress : $complaint->status,
            'first_response_at' => $complaint->first_response_at ?? now(),
        ]);

        $this->logEvent($complaint, $actor, $actor->name.' assigned the ticket to '.$assignee->name.'.');
        $this->notify([$assignee], 'Ticket '.$complaint->ticket_no.' assigned to you', $actor->name.' assigned you "'.$complaint->subject.'". '.$complaint->slaLabel().'.', $complaint, 'warning');

        if (! $complaint->isOwnedBy($assignee)) {
            $this->notify([$complaint->raiser], 'Ticket '.$complaint->ticket_no.' assigned', 'Your ticket is now with '.$assignee->name.'.', $complaint, 'info');
        }
    }

    /**
     * Who $actor may hand this ticket to: everyone in its handler team
     * (Admin: every active user).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function assignableUsersFor(Complaint $complaint, User $actor): \Illuminate\Support\Collection
    {
        $query = User::query()->where('is_active', true)->orderBy('name');

        if (! $actor->hasRole('Admin')) {
            $roles = $complaint->handler_role
                ? $complaint->category->handlerRoles() ?: [$complaint->handler_role]
                : [];

            if ($roles === []) {
                // A supervisor-routed ticket: only the assignee (or the
                // escalation recipient) holds it, and they may pass it up.
                return $this->supervisorOptionsFor($complaint->raiser)->put((int) $actor->getKey(), $actor->name);
            }

            $query->role($roles);
        }

        return $query->pluck('name', 'id');
    }

    public function hold(Complaint $complaint, User $actor, string $reason): void
    {
        $this->assertCanHandle($complaint, $actor);
        $this->assertUnresolved($complaint);

        $complaint->update(['status' => ComplaintStatus::OnHold]);

        $this->logEvent($complaint, $actor, $actor->name.' put the ticket on hold: '.$reason);
        $this->notify([$complaint->raiser], 'Ticket '.$complaint->ticket_no.' on hold', $reason, $complaint, 'warning');
    }

    public function resume(Complaint $complaint, User $actor): void
    {
        $this->assertCanHandle($complaint, $actor);
        $this->assertUnresolved($complaint);

        $complaint->update(['status' => ComplaintStatus::InProgress, 'assigned_to' => $complaint->assigned_to ?? $actor->getKey()]);

        $this->logEvent($complaint, $actor, $actor->name.' resumed work on the ticket.');
    }

    public function resolve(Complaint $complaint, User $actor, string $note): void
    {
        $this->assertCanHandle($complaint, $actor);
        $this->assertUnresolved($complaint);

        $now = now();

        $complaint->update([
            'status' => ComplaintStatus::Resolved,
            'assigned_to' => $complaint->assigned_to ?? $actor->getKey(),
            'resolved_at' => $now,
            'resolved_by' => $actor->getKey(),
            'resolution_note' => $note,
            'first_response_at' => $complaint->first_response_at ?? $now,
        ]);

        $this->logEvent($complaint, $actor, $actor->name.' resolved the ticket: '.$note);
        $this->notify(
            [$complaint->raiser],
            'Ticket '.$complaint->ticket_no.' resolved',
            $note.' — close the ticket if this settles it, or reopen it if not.',
            $complaint,
            'success',
        );
    }

    /** The raiser accepts the resolution (or the Admin closes it outright). */
    public function close(Complaint $complaint, User $actor, ?string $note = null): void
    {
        if (! $complaint->isOwnedBy($actor) && ! $actor->hasRole('Admin')) {
            throw new AuthorizationException('Only the person who raised the ticket (or whom it was raised for), or an Admin, may close it.');
        }

        if ($complaint->status === ComplaintStatus::Closed) {
            return;
        }

        $now = now();

        $complaint->update([
            'status' => ComplaintStatus::Closed,
            'closed_at' => $now,
            'closed_by' => $actor->getKey(),
            'resolved_at' => $complaint->resolved_at ?? $now,
            'resolved_by' => $complaint->resolved_by ?? $actor->getKey(),
        ]);

        $this->logEvent($complaint, $actor, $actor->name.' closed the ticket.'.($note ? ' '.$note : ''));

        $handlers = $this->handlersOf($complaint)->reject(fn (User $user): bool => (int) $user->getKey() === (int) $actor->getKey());
        $this->notify($handlers, 'Ticket '.$complaint->ticket_no.' closed', $actor->name.' closed "'.$complaint->subject.'".'.($note ? ' '.$note : ''), $complaint, 'gray');
    }

    /** The raiser (or Admin) sends a resolved / closed ticket back; the SLA restarts. */
    public function reopen(Complaint $complaint, User $actor, string $reason): void
    {
        if (! $complaint->isOwnedBy($actor) && ! $actor->hasRole('Admin')) {
            throw new AuthorizationException('Only the person who raised the ticket (or whom it was raised for), or an Admin, may reopen it.');
        }

        if ($complaint->isUnresolved()) {
            return;
        }

        $now = now();

        $complaint->update([
            'status' => ComplaintStatus::Open,
            'sla_started_at' => $now,
            'due_at' => $now->copy()->addMinutes($complaint->priority->resolve_within_minutes),
            'escalation_level' => 0,
            'escalated_at' => null,
            'escalated_to' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_note' => null,
            'closed_at' => null,
            'closed_by' => null,
            'reopened_count' => $complaint->reopened_count + 1,
        ]);

        $this->logEvent($complaint, $actor, $actor->name.' reopened the ticket: '.$reason);
        $this->notify($this->handlersOf($complaint), 'Ticket '.$complaint->ticket_no.' reopened', $reason.' New deadline: '.$complaint->due_at->format('d M, h:i A').'.', $complaint, 'danger');
    }

    /** Admin changes the priority; the deadline is recomputed from when the SLA last started. */
    public function reprioritise(Complaint $complaint, User $actor, ComplaintPriority $priority): void
    {
        if (! $actor->hasRole('Admin')) {
            throw new AuthorizationException('Only an Admin may change a ticket\'s priority.');
        }

        if ((int) $complaint->priority_id === (int) $priority->id) {
            return;
        }

        $from = $complaint->priority->name;
        $startedAt = $complaint->sla_started_at ?? $complaint->created_at ?? now();

        $complaint->update([
            'priority_id' => $priority->id,
            'due_at' => $complaint->isUnresolved() ? $startedAt->copy()->addMinutes($priority->resolve_within_minutes) : $complaint->due_at,
        ]);

        $complaint->unsetRelation('priority');

        $this->logEvent($complaint, $actor, sprintf('%s changed the priority from %s to %s. New deadline: %s.', $actor->name, $from, $priority->name, $complaint->due_at?->format('d M, h:i A') ?? '—'));
        $this->notify($this->everyoneOn($complaint)->reject(fn (User $user): bool => (int) $user->getKey() === (int) $actor->getKey()), 'Ticket '.$complaint->ticket_no.' re-prioritised to '.$priority->name, 'Resolve by '.($complaint->due_at?->format('d M, h:i A') ?? '—').'.', $complaint, 'warning');
    }

    /** Adds a comment; internal ones are visible to handlers only. */
    public function comment(Complaint $complaint, User $author, string $body, bool $internal = false): ComplaintComment
    {
        if (! $this->canView($complaint, $author)) {
            throw new AuthorizationException('You cannot comment on this ticket.');
        }

        if ($internal && ! $this->canHandle($complaint, $author)) {
            $internal = false;
        }

        $comment = $complaint->comments()->create([
            'user_id' => $author->getKey(),
            'type' => ComplaintComment::TYPE_COMMENT,
            'body' => $body,
            'is_internal' => $internal,
        ]);

        if (! $complaint->first_response_at && $this->canHandle($complaint, $author) && ! $complaint->isOwnedBy($author)) {
            $complaint->update(['first_response_at' => now()]);
        }

        $recipients = $internal
            ? $this->handlersOf($complaint)
            : $this->everyoneOn($complaint);

        $this->notify(
            $recipients->reject(fn (User $user): bool => (int) $user->getKey() === (int) $author->getKey()),
            'New comment on ticket '.$complaint->ticket_no,
            $author->name.': '.str($body)->limit(140),
            $complaint,
            'info',
        );

        return $comment;
    }

    /**
     * Whether $user may see the ticket at all: Admin, the raiser, the
     * assignee, the escalation recipient, or a member of the handling team.
     */
    public function canView(Complaint $complaint, User $user): bool
    {
        if ($user->hasRole('Admin') || $complaint->isOwnedBy($user) || $this->canHandle($complaint, $user)) {
            return true;
        }

        // A supervisor follows every ticket held by somebody under them —
        // which is also what keeps an earlier escalation recipient on it
        // after the ticket climbs another level.
        $holderEmployeeId = $complaint->assignee?->employee_id;

        return $holderEmployeeId !== null
            && $this->subordinateEmployeeIds($user)->contains((int) $holderEmployeeId);
    }

    /**
     * The employees below $user in the reporting tree (visibility walk),
     * excluding $user themselves; empty for anyone without an employee row.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function subordinateEmployeeIds(User $user): \Illuminate\Support\Collection
    {
        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return HierarchyHelper::visibleSubordinateIds($employee)
            ->reject(fn (int $id): bool => $id === (int) $employee->id)
            ->values();
    }

    /** Whether $user may work the ticket (take up, assign, resolve, ...). */
    public function canHandle(Complaint $complaint, User $user): bool
    {
        if ($user->hasRole('Admin') || $complaint->isAssignedTo($user) || $complaint->isEscalatedTo($user)) {
            return true;
        }

        if ($complaint->handler_role === null) {
            return false;
        }

        $roles = $complaint->category->handlerRoles() ?: [$complaint->handler_role];

        return $user->hasAnyRole($roles);
    }

    /**
     * Scopes a query to the tickets $user may see.
     */
    public function scopeVisible(Builder $query, User $user): Builder
    {
        if ($user->hasRole('Admin')) {
            return $query;
        }

        $roleNames = $user->roles->pluck('name')->all();

        $subordinateIds = $this->subordinateEmployeeIds($user);

        return $query->where(function (Builder $query) use ($user, $roleNames, $subordinateIds): void {
            $query->where('raised_by', $user->getKey())
                ->orWhere('on_behalf_of', $user->getKey())
                ->orWhere('assigned_to', $user->getKey())
                ->orWhere('escalated_to', $user->getKey());

            if ($subordinateIds->isNotEmpty()) {
                $query->orWhereIn('assigned_to', User::query()->whereIn('employee_id', $subordinateIds)->select('id'));
            }

            if ($roleNames !== []) {
                // Any handler role on the category, not only the first one
                // stamped on the ticket, so an Asset ticket (Admin + IT)
                // shows in both queues.
                $query->orWhere(function (Builder $team) use ($roleNames): void {
                    $team->whereNotNull('handler_role')
                        ->whereHas('category', function (Builder $category) use ($roleNames): void {
                            $category->where(function (Builder $roles) use ($roleNames): void {
                                foreach ($roleNames as $role) {
                                    $roles->orWhereJsonContains('handler_roles', $role);
                                }
                            });
                        });
                });
            }
        });
    }

    /**
     * Everyone currently responsible: the assignee if there is one, else
     * the whole handler team; plus whoever it was escalated to.
     *
     * @return Collection<int, User>
     */
    public function handlersOf(Complaint $complaint): Collection
    {
        $ids = collect([$complaint->assigned_to, $complaint->escalated_to])->filter();

        if ($complaint->assigned_to === null && $complaint->handler_role !== null) {
            $roles = $complaint->category->handlerRoles() ?: [$complaint->handler_role];

            $ids = $ids->merge(User::query()->role($roles)->where('is_active', true)->pluck('id'));
        }

        return User::query()->whereIn('id', $ids->unique()->values())->get();
    }

    /**
     * Handlers plus the raiser.
     *
     * @return Collection<int, User>
     */
    public function everyoneOn(Complaint $complaint): Collection
    {
        $everyone = $this->handlersOf($complaint)->push($complaint->raiser);

        if ($complaint->beneficiary) {
            $everyone->push($complaint->beneficiary);
        }

        return $everyone->unique('id')->values();
    }

    public function logEvent(Complaint $complaint, ?User $actor, string $body): ComplaintComment
    {
        return $complaint->comments()->create([
            'user_id' => $actor?->getKey(),
            'type' => ComplaintComment::TYPE_SYSTEM,
            'body' => $body,
            'is_internal' => false,
        ]);
    }

    /**
     * One bell notification per recipient, filed under the Help Desk tab
     * and linking to the ticket.
     *
     * @param  iterable<int, User>  $recipients
     */
    public function notify(iterable $recipients, string $title, string $body, Complaint $complaint, string $color = 'info'): void
    {
        $recipients = collect($recipients)->filter()->unique(fn (User $user): int => (int) $user->getKey())->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon(NotificationCategory::HelpDesk->icon())
            ->iconColor($color)
            ->viewData([...NotificationCategory::HelpDesk->viewData(), 'complaint_id' => $complaint->id])
            ->actions($this->openTicketAction($complaint))
            ->sendToDatabase($recipients);
    }

    /**
     * @return array<int, Action>
     */
    private function openTicketAction(Complaint $complaint): array
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? (DemoContext::isActive() ? 'demo' : 'admin');

        try {
            $url = ComplaintResource::getUrl('view', ['record' => $complaint], panel: $panel);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return [
            Action::make('openTicket')
                ->label('Open ticket')
                ->url($url)
                ->markAsRead(),
        ];
    }

    private function assertCanHandle(Complaint $complaint, User $actor): void
    {
        if (! $this->canHandle($complaint, $actor)) {
            throw new AuthorizationException('This ticket is not in your queue.');
        }
    }

    private function assertUnresolved(Complaint $complaint): void
    {
        if (! $complaint->isUnresolved()) {
            throw new AuthorizationException('This ticket is already '.$complaint->status->label().'. Reopen it first.');
        }
    }

    /**
     * The name of the level a ticket escalates to — for the thread and the
     * bell — shared with the escalation runner.
     */
    public static function describeDeadline(Carbon $dueAt): string
    {
        return $dueAt->format('d M Y, h:i A');
    }
}
