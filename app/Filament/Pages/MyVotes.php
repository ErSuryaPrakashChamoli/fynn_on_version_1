<?php

namespace App\Filament\Pages;

use App\Models\Poll;
use App\Models\PollRecipient;
use App\Models\User;
use App\Services\Voting\PollService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Setting → My Votes: every poll sent to the signed-in user — the ones
 * still waiting for their vote (with the vote form) and the ones they have
 * answered or that have closed. Open to every role; this is where optional
 * polls get answered (mandatory ones also block through PollPrompt).
 */
class MyVotes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandThumbUp;

    protected static string|UnitEnum|null $navigationGroup = 'Setting';

    protected static ?string $navigationLabel = 'My Votes';

    protected static ?string $title = 'My Votes';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.my-votes';

    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof User;
    }

    public function getSubheading(): ?string
    {
        return 'Polls and feedback requests sent to you. Vote on the open ones here; mandatory ones also ask as soon as you sign in.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PollRecipient::query()
                ->where('user_id', Filament::auth()->id())
                ->with('poll.creator', 'poll.type'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('poll.title')
                    ->label('Poll')
                    ->searchable()
                    ->description(fn (PollRecipient $record): string => str($record->poll?->question ?? '')->limit(100))
                    ->wrap(),

                TextColumn::make('poll.type.name')
                    ->label('Type'),

                TextColumn::make('poll.creator.name')
                    ->label('Raised by')
                    ->placeholder('Admin'),

                TextColumn::make('poll_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (PollRecipient $record): string => $record->hasVoted()
                        ? 'Voted'
                        : ($record->poll?->isLive() ? ($record->poll->is_mandatory ? 'Vote required' : 'Open') : 'Closed'))
                    ->color(fn (string $state): string => match ($state) {
                        'Voted' => 'success',
                        'Vote required' => 'warning',
                        'Open' => 'info',
                        default => 'gray',
                    }),

                IconColumn::make('poll.is_anonymous')
                    ->label('Anonymous')
                    ->boolean(),

                TextColumn::make('poll.expires_at')
                    ->label('Closes')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('Until closed'),

                TextColumn::make('voted_at')
                    ->label('Voted at')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('voted_at')
                    ->label('Voted')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('vote')
                    ->label('Vote')
                    ->icon('heroicon-o-hand-thumb-up')
                    ->color('primary')
                    ->visible(fn (PollRecipient $record): bool => ! $record->hasVoted() && (bool) $record->poll?->isLive())
                    ->modalHeading(fn (PollRecipient $record): string => $record->poll->title)
                    ->modalDescription(fn (PollRecipient $record): string => $record->poll->question)
                    ->schema(fn (PollRecipient $record): array => array_values(array_filter([
                        Select::make('option')
                            ->label('Your answer')
                            ->options($record->poll->optionChoices())
                            ->searchable(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('reason', null)),
                        $record->poll->ask_reason
                            ? Select::make('reason')
                                ->label('Reason')
                                ->options(fn (Get $get): array => $record->poll->reasonChoices($get('option')))
                                ->searchable(false)
                                ->visible(fn (Get $get): bool => filled($get('option')) && $record->poll->reasonsFor($get('option')) !== [])
                                ->required(fn (Get $get): bool => filled($get('option')) && $record->poll->reasonsFor($get('option')) !== [])
                            : null,
                        $record->poll->allow_comment
                            ? Textarea::make('comment')->label('Comment (optional)')->rows(3)->maxLength(1000)
                            : null,
                    ])))
                    ->action(function (PollRecipient $record, array $data): void {
                        $user = Filament::auth()->user();

                        if (! $user instanceof User || ! $record->poll instanceof Poll) {
                            return;
                        }

                        try {
                            app(PollService::class)->vote($record->poll, $user, (string) $data['option'], $data['comment'] ?? null, $data['reason'] ?? null);
                        } catch (AuthorizationException|ValidationException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Thank you, your vote is recorded')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No polls yet')
            ->emptyStateDescription('Polls and feedback requests sent to you will appear here.');
    }
}
