<?php

namespace App\Filament\Resources\CustomerEditRequests;

use App\Models\CustomerEditRequest;
use App\Models\User;
use App\Services\CustomerEditRequestService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only approve / reject, shared by the listing rows and the request
 * page. Both re-check the reviewer inside the service.
 */
class CustomerEditRequestActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve & apply')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this edit request')
            ->modalDescription(fn (CustomerEditRequest $record): string => 'The requested values for '.$record->fieldsLabel().' are written onto '.$record->customer?->customer_name.'\'s file straight away, and the change is logged.')
            ->schema([
                Textarea::make('review_note')->label('Note (optional)')->rows(2)->maxLength(1000),
            ])
            ->visible(fn (CustomerEditRequest $record): bool => CustomerEditRequestResource::isReviewer() && $record->isPending())
            ->action(function (CustomerEditRequest $record, array $data): void {
                self::run(fn (User $user) => app(CustomerEditRequestService::class)->approve($record, $user, $data['review_note'] ?? null), 'Approved — the file has been updated');
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Reject this edit request')
            ->modalDescription('Nothing on the file changes. The person who raised it is told why.')
            ->schema([
                Textarea::make('review_note')->label('Why is it rejected?')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerEditRequest $record): bool => CustomerEditRequestResource::isReviewer() && $record->isPending())
            ->action(function (CustomerEditRequest $record, array $data): void {
                self::run(fn (User $user) => app(CustomerEditRequestService::class)->reject($record, $user, (string) $data['review_note']), 'Request rejected');
            });
    }

    protected static function run(\Closure $callback, string $success): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $callback($user);
        } catch (AuthorizationException|ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }
}
