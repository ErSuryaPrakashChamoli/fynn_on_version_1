<?php

namespace App\Filament\Resources\Complaints\Schemas;

use App\Models\ComplaintCategory;
use App\Models\ComplaintPriority;
use App\Models\ComplaintReason;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ComplaintForm
{
    public const ATTACHMENT_DISK = 'public';

    public const ATTACHMENT_DIRECTORY = 'help-desk';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who is it for?')
                    ->description('A supervisor can raise a ticket for somebody in their own team.')
                    ->schema([
                        ToggleButtons::make('raised_for')
                            ->label('Raising this for')
                            ->options([
                                'self' => 'Myself',
                                'team' => 'Someone in my team',
                            ])
                            ->icons([
                                'self' => 'heroicon-o-user',
                                'team' => 'heroicon-o-user-group',
                            ])
                            ->inline()
                            ->default('self')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('on_behalf_of', null)),

                        Select::make('on_behalf_of')
                            ->label('Team member')
                            ->options(fn (): array => self::teamMemberOptions())
                            ->preload()
                            ->required(fn (Get $get): bool => $get('raised_for') === 'team')
                            ->visible(fn (Get $get): bool => $get('raised_for') === 'team')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (): string => self::teamMemberOptions() === []
                                ? 'Nobody with a login reports to you yet — ask the Admin to check your team\'s reporting.'
                                : 'Everyone in your reporting line who has a login.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (string $operation): bool => $operation === 'create' && self::canRaiseForOthers()),

                Section::make('What is it about?')
                    ->description('Pick the category and the reason; the ticket goes to the team that handles it, or to a supervisor you choose.')
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => ComplaintCategory::options())
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function (Set $set): void {
                                $set('reason_id', null);
                                $set('escalate_to', null);
                            }),

                        Select::make('reason_id')
                            ->label('Reason for complaint')
                            ->options(fn (Get $get): array => self::reasonOptions($get('category_id')))
                            ->required(fn (Get $get): bool => self::reasonOptions($get('category_id')) !== [])
                            ->disabled(fn (Get $get, string $operation): bool => $operation === 'edit' || blank($get('category_id')))
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->placeholder(fn (Get $get): string => blank($get('category_id')) ? 'Pick a category first' : 'Select a reason'),

                        Select::make('priority_id')
                            ->label('Priority')
                            ->options(fn (): array => ComplaintPriority::options())
                            ->required()
                            ->disabledOn('edit')
                            ->helperText('The deadline to resolve it follows from the priority. Only an Admin can change it afterwards.'),

                        Select::make('escalate_to')
                            ->label('Send to')
                            ->options(fn (): array => self::supervisorOptions())
                            ->required(fn (Get $get): bool => self::routesToSupervisor($get('category_id')))
                            ->visible(fn (Get $get): bool => self::routesToSupervisor($get('category_id')))
                            ->hiddenOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (): string => self::supervisorOptions() === []
                                ? 'No supervisor with a login was found above you in the reporting line — ask the Admin to fix your reporting.'
                                : 'Your Team Leader, Manager, Cluster Manager or Business Head.'),

                        Placeholder::make('routed_to')
                            ->label('Goes to')
                            ->content(fn (Get $get): HtmlString => new HtmlString(self::routingNote($get('category_id'))))
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'create'
                                && filled($get('category_id'))
                                && ! self::routesToSupervisor($get('category_id'))),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Details')
                    ->schema([
                        Textarea::make('description')
                            ->label('Describe the problem')
                            ->rows(6)
                            ->maxLength(5000)
                            ->helperText('Optional — what happened, since when, and what you have already tried.')
                            ->columnSpanFull(),

                        FileUpload::make('attachments')
                            ->label('Screenshots / documents')
                            ->disk(self::ATTACHMENT_DISK)
                            ->directory(self::ATTACHMENT_DIRECTORY)
                            ->visibility('public')
                            ->multiple()
                            ->maxFiles(5)
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->openable()
                            ->downloadable()
                            ->appendFiles()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function reasonOptions(mixed $categoryId): array
    {
        if (blank($categoryId)) {
            return [];
        }

        return ComplaintReason::query()
            ->where('category_id', (int) $categoryId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function routesToSupervisor(mixed $categoryId): bool
    {
        return filled($categoryId)
            && (bool) ComplaintCategory::query()->find((int) $categoryId)?->routesToSupervisor();
    }

    protected static function routingNote(mixed $categoryId): string
    {
        $category = filled($categoryId) ? ComplaintCategory::query()->find((int) $categoryId) : null;

        if (! $category) {
            return '';
        }

        return 'The <strong>'.e(implode(', ', $category->handlerRoles()) ?: 'Admin').'</strong> team. '
            .e((string) $category->description);
    }

    protected static function canRaiseForOthers(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(ComplaintService::class)->canRaiseForOthers($user);
    }

    /**
     * @return array<int, string>
     */
    protected static function teamMemberOptions(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? app(ComplaintService::class)->teamMemberOptionsFor($user)->all()
            : [];
    }

    /**
     * @return array<int, string>
     */
    protected static function supervisorOptions(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? app(ComplaintService::class)->supervisorOptionsFor($user)->all()
            : [];
    }
}
