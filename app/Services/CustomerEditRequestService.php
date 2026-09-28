<?php

namespace App\Services;

use App\Enums\CustomerEditRequestStatus;
use App\Enums\NotificationCategory;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use App\Models\Customer;
use App\Models\CustomerEditRequest;
use App\Models\CustomerEditRequestItem;
use App\Models\User;
use App\Support\CustomerEditableFields;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Change requests on customer files. Whoever may work on a file (its owner,
 * anyone above them, or a continuity stand-in — the same rule as
 * eligibility requests) asks for new values on one journey section with a
 * reason; the Admin approves or rejects. Approving writes the values onto
 * the customer in one save and keeps the before/after on the request's
 * items, alongside the customer's own activity log entry.
 */
class CustomerEditRequestService
{
    public function __construct(private CustomerEligibilityService $eligibility) {}

    public static function isReviewer(?User $user): bool
    {
        return $user !== null && $user->hasRole('Admin');
    }

    public function canRaiseOn(?User $user, Customer $customer): bool
    {
        return $this->eligibility->canWorkOn($user, $customer);
    }

    /**
     * @param  array<int, array{field: string, value: string|null}>  $changes
     */
    public function raise(User $user, Customer $customer, string $section, string $reason, array $changes): CustomerEditRequest
    {
        if (! $this->canRaiseOn($user, $customer)) {
            throw new AuthorizationException('You can only request changes on files in your own team.');
        }

        if (! array_key_exists($section, CustomerEditableFields::sections())) {
            throw ValidationException::withMessages(['section' => 'Pick one of the listed sections.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the details need changing.']);
        }

        $rows = $this->validatedChanges($customer, $section, $changes);

        $request = DB::transaction(function () use ($user, $customer, $section, $reason, $rows): CustomerEditRequest {
            $request = CustomerEditRequest::query()->create([
                'customer_id' => $customer->getKey(),
                'section' => $section,
                'reason' => trim($reason),
                'status' => CustomerEditRequestStatus::Pending,
                'requested_by' => $user->getKey(),
            ]);

            foreach ($rows as $row) {
                $request->items()->create($row);
            }

            return $request->load('items');
        });

        $this->notify(
            User::query()->role('Admin')->where('is_active', true)->get(),
            'Edit request: '.$customer->customer_name,
            sprintf('%s asked to change %s (%s). Reason: %s', $user->name, $request->fieldsLabel(), $request->sectionLabel(), str($reason)->limit(120)),
            'warning',
        );

        return $request;
    }

    /**
     * Writes every requested value onto the customer and records what was
     * overwritten. Refused unless the reviewer is an Admin and the request
     * is still pending.
     */
    public function approve(CustomerEditRequest $request, User $reviewer, ?string $note = null): void
    {
        $this->assertReviewable($request, $reviewer);

        DB::transaction(function () use ($request, $reviewer, $note): void {
            /** @var Customer $customer */
            $customer = Customer::query()->lockForUpdate()->findOrFail($request->customer_id);
            $now = now();
            $changes = [];

            foreach ($request->items as $item) {
                $overwritten = CustomerEditableFields::currentValue($customer, $item->field);

                $customer->setAttribute(
                    $item->field,
                    CustomerEditableFields::cast($request->section, $item->field, $item->requested_value),
                );

                $item->forceFill(['overwritten_value' => $overwritten, 'applied_at' => $now])->save();

                $changes[$item->field] = ['old' => $overwritten, 'new' => $item->requested_value];
            }

            $customer->save();

            $request->forceFill([
                'status' => CustomerEditRequestStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => $now,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'applied_at' => $now,
            ])->save();

            activity()
                ->performedOn($customer)
                ->causedBy($reviewer)
                ->event('updated')
                ->withProperties([
                    'customer_edit_request_id' => $request->getKey(),
                    'section' => $request->section,
                    'requested_by' => $request->requested_by,
                    'reason' => $request->reason,
                    'changes' => $changes,
                ])
                ->log('Customer details changed through edit request #'.$request->getKey());
        });

        $this->notifyRequester($request, 'approved and applied', 'success', $note);
    }

    public function reject(CustomerEditRequest $request, User $reviewer, string $note): void
    {
        $this->assertReviewable($request, $reviewer);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['review_note' => 'Say why the request is rejected.']);
        }

        $request->forceFill([
            'status' => CustomerEditRequestStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => trim($note),
        ])->save();

        $this->notifyRequester($request, 'rejected', 'danger', $note);
    }

    /**
     * Fields of $section that already have a pending request on the file —
     * a second request for the same field would race the first.
     *
     * @return list<string>
     */
    public function fieldsPendingOn(Customer $customer, ?string $section = null): array
    {
        return CustomerEditRequestItem::query()
            ->whereHas('request', fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->pending()
                ->when($section, fn ($query) => $query->where('section', $section)))
            ->pluck('field')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{field: string, value: string|null}>  $changes
     * @return list<array{field: string, current_value: string|null, requested_value: string|null}>
     */
    private function validatedChanges(Customer $customer, string $section, array $changes): array
    {
        $rows = [];
        $seen = [];
        $pending = $this->fieldsPendingOn($customer);

        foreach (array_values($changes) as $index => $change) {
            $field = (string) ($change['field'] ?? '');
            $key = "changes.{$index}.value";

            if (! CustomerEditableFields::isEditable($section, $field)) {
                throw ValidationException::withMessages(["changes.{$index}.field" => 'Pick a field of the chosen section.']);
            }

            if (isset($seen[$field])) {
                throw ValidationException::withMessages(["changes.{$index}.field" => CustomerEditableFields::fieldLabel($section, $field).' is listed twice.']);
            }

            if (in_array($field, $pending, true)) {
                throw ValidationException::withMessages(["changes.{$index}.field" => CustomerEditableFields::fieldLabel($section, $field).' already has a pending request on this file.']);
            }

            $seen[$field] = true;
            $value = $change['value'] ?? null;
            $value = $value === null ? null : trim((string) $value);
            $value = $value === '' ? null : $value;

            match (CustomerEditableFields::type($section, $field)) {
                CustomerEditableFields::TYPE_NUMBER => $value !== null && (! is_numeric($value) || (float) $value < 0)
                    ? throw ValidationException::withMessages([$key => 'Enter an amount of zero or more.'])
                    : null,
                CustomerEditableFields::TYPE_DATE => $value !== null && ! strtotime($value)
                    ? throw ValidationException::withMessages([$key => 'Enter a valid date.'])
                    : null,
                CustomerEditableFields::TYPE_SELECT => $value !== null && ! array_key_exists($value, CustomerEditableFields::choices($field))
                    ? throw ValidationException::withMessages([$key => 'Pick one of the listed options.'])
                    : null,
                default => null,
            };

            if ($value !== null && CustomerEditableFields::type($section, $field) === CustomerEditableFields::TYPE_DATE) {
                $value = date('Y-m-d', (int) strtotime($value));
            }

            $current = CustomerEditableFields::currentValue($customer, $field);

            if ($this->same($current, $value)) {
                throw ValidationException::withMessages([$key => 'This is already the current value.']);
            }

            $rows[] = ['field' => $field, 'current_value' => $current, 'requested_value' => $value];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['changes' => 'Add at least one field to change.']);
        }

        return $rows;
    }

    private function same(?string $current, ?string $requested): bool
    {
        if ($current === null || $requested === null) {
            return $current === $requested;
        }

        if (is_numeric($current) && is_numeric($requested)) {
            return (float) $current === (float) $requested;
        }

        return $current === $requested;
    }

    private function assertReviewable(CustomerEditRequest $request, User $reviewer): void
    {
        if (! self::isReviewer($reviewer)) {
            throw new AuthorizationException('Only an Admin can review an edit request.');
        }

        if (! $request->isPending()) {
            throw new AuthorizationException('This request has already been '.strtolower($request->status->label()).'.');
        }
    }

    private function notifyRequester(CustomerEditRequest $request, string $outcome, string $color, ?string $note): void
    {
        $requester = $request->requester;

        if (! $requester) {
            return;
        }

        $this->notify(
            collect([$requester]),
            'Edit request '.$outcome.': '.$request->customer?->customer_name,
            trim($request->fieldsLabel().' ('.$request->sectionLabel().').'.(filled($note) ? ' Admin note: '.$note : '')),
            $color,
        );
    }

    /**
     * @param  Collection<int, User>|iterable<int, User>  $recipients
     */
    private function notify(iterable $recipients, string $title, string $body, string $color): void
    {
        $recipients = collect($recipients)->filter()->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon(NotificationCategory::CustomerEdit->icon())
                ->iconColor($color)
                ->viewData(NotificationCategory::CustomerEdit->viewData())
                ->actions([
                    Action::make('open')
                        ->label('Open requests')
                        ->url(CustomerEditRequestResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (Throwable $exception) {
            // A failed notification must never block the request itself.
            report($exception);
        }
    }
}
