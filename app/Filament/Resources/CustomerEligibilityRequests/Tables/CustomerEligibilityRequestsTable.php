<?php

namespace App\Filament\Resources\CustomerEligibilityRequests\Tables;

use App\Enums\EligibilityRequestStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerEligibilityRequest;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

class CustomerEligibilityRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.customer_name')
                    ->label('Customer')
                    ->description(fn (CustomerEligibilityRequest $record): ?string => $record->customer?->mobile_no)
                    ->url(fn (CustomerEligibilityRequest $record): ?string => $record->customer
                        ? CustomerResource::getUrl('view', ['record' => $record->customer])
                        : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.assignedTo.emp_name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (EligibilityRequestStatus $state): string => $state->label())
                    ->color(fn (EligibilityRequestStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),

                TextColumn::make('requester.name')
                    ->label('Raised by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('review_note')
                    ->label('Review note')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(EligibilityRequestStatus::options()),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    protected static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Approve eligibility request')
            ->modalDescription('The file becomes Eligible, moves to SFL, and its eligibility is locked.')
            ->schema([
                Textarea::make('review_note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->visible(fn (CustomerEligibilityRequest $record): bool => CustomerEligibilityService::isReviewer(Filament::auth()->user())
                && $record->isPending())
            ->action(fn (CustomerEligibilityRequest $record, array $data) => self::review(
                fn (User $user) => app(CustomerEligibilityService::class)->approve($user, $record, $data['review_note'] ?? null),
                'Request approved — file is now Eligible',
            ));
    }

    protected static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Reject eligibility request')
            ->schema([
                Textarea::make('review_note')
                    ->label('Why is it rejected?')
                    ->rows(2)
                    ->maxLength(1000)
                    ->required(),
            ])
            ->visible(fn (CustomerEligibilityRequest $record): bool => CustomerEligibilityService::isReviewer(Filament::auth()->user())
                && $record->isPending())
            ->action(fn (CustomerEligibilityRequest $record, array $data) => self::review(
                fn (User $user) => app(CustomerEligibilityService::class)->reject($user, $record, $data['review_note']),
                'Request rejected',
            ));
    }

    /**
     * @param  callable(User): mixed  $callback
     */
    private static function review(callable $callback, string $successTitle): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        try {
            $callback($user);
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
