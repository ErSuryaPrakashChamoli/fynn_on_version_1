<?php

namespace App\Filament\Resources\Polls\Schemas;

use App\Models\Employee;
use App\Models\Poll;
use App\Models\PollType;
use Coolsam\Flatpickr\Forms\Components\Flatpickr;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

class PollForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Poll')
                    ->description('The answer dropdown comes from the poll type, which only the Admin defines (Setting → Poll Types).')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),

                        Textarea::make('question')
                            ->label('Question / what you want feedback on')
                            ->required()
                            ->rows(4)
                            ->maxLength(3000)
                            ->columnSpanFull(),

                        Select::make('poll_type_id')
                            ->label('Type of voting')
                            ->options(fn (): array => PollType::options())
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText('Sets the options people choose from.'),

                        Placeholder::make('options_preview')
                            ->label('Options people will see')
                            ->content(fn (Get $get, ?Poll $record): HtmlString => new HtmlString(self::optionsPreview($get('poll_type_id'), $record))),

                        Toggle::make('allow_comment')
                            ->label('Also ask for a written comment')
                            ->default(true)
                            ->disabledOn('edit'),

                        Toggle::make('ask_reason')
                            ->label('Ask for a reason')
                            ->helperText(fn (Get $get, ?Poll $record): string => self::reasonHelp($get('poll_type_id'), $record))
                            ->default(false)
                            ->disabledOn('edit')
                            ->disabled(fn (Get $get, ?Poll $record, string $operation): bool => $operation === 'edit' || ! self::typeHasReasons($get('poll_type_id')))
                            ->dehydrated(),

                        Toggle::make('is_mandatory')
                            ->label('Mandatory')
                            ->helperText('Everyone it is sent to must vote before they can carry on using the LMS.')
                            ->default(false)
                            ->disabledOn('edit'),

                        Toggle::make('is_anonymous')
                            ->label('Anonymous voting')
                            ->helperText('Answers are stored without names. You still see who has and has not voted.')
                            ->default(false)
                            ->disabledOn('edit'),

                        Flatpickr::make('expires_at')
                            ->label('Voting closes at')
                            ->time(true)
                            ->time24hr(false)
                            ->seconds(false)
                            ->minuteIncrement(15)
                            ->format('Y-m-d H:i')
                            ->displayFormat('d M Y h:i K')
                            ->minDate(today())
                            ->rule('after:now')
                            ->validationMessages(['after' => 'Pick a time in the future.'])
                            ->placeholder('Select date & time')
                            ->suffixIcon('heroicon-m-calendar')
                            ->helperText('Leave blank to keep it open until you close it.'),

                        Toggle::make('is_active')
                            ->label('Open for voting')
                            ->helperText('Switch off to close the poll early. Votes already cast are kept.')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Send to')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'Pick who votes. Only people whose login is active get it.'
                        : 'The audience is fixed once a poll has been sent.')
                    ->schema([
                        ToggleButtons::make('audience')
                            ->label('Audience')
                            ->options(Poll::AUDIENCES)
                            ->icons([
                                Poll::AUDIENCE_COMPANY => 'heroicon-o-building-office-2',
                                Poll::AUDIENCE_ROLES => 'heroicon-o-key',
                                Poll::AUDIENCE_DESIGNATIONS => 'heroicon-o-identification',
                            ])
                            ->inline()
                            ->default(Poll::AUDIENCE_COMPANY)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->columnSpanFull(),

                        Select::make('audience_roles')
                            ->label('Roles')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->required(fn (Get $get): bool => $get('audience') === Poll::AUDIENCE_ROLES)
                            ->visible(fn (Get $get): bool => $get('audience') === Poll::AUDIENCE_ROLES)
                            ->disabledOn('edit')
                            ->columnSpanFull(),

                        Select::make('audience_designations')
                            ->label('Designations')
                            ->multiple()
                            ->preload()
                            ->options(Employee::designationOptions())
                            ->required(fn (Get $get): bool => $get('audience') === Poll::AUDIENCE_DESIGNATIONS)
                            ->visible(fn (Get $get): bool => $get('audience') === Poll::AUDIENCE_DESIGNATIONS)
                            ->disabledOn('edit')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function typeHasReasons(mixed $typeId): bool
    {
        return filled($typeId) && (bool) PollType::query()->find((int) $typeId)?->hasReasons();
    }

    protected static function reasonHelp(mixed $typeId, ?Poll $record): string
    {
        if ($record?->exists) {
            return $record->ask_reason ? 'Voters pick a reason from the dropdown defined on the poll type.' : 'This poll does not ask for a reason.';
        }

        if (blank($typeId)) {
            return 'Available once a type of voting with reasons is picked.';
        }

        return self::typeHasReasons($typeId)
            ? 'Voters also pick a reason from the dropdown the Admin defined on this type (Setting → Poll Types).'
            : 'This type has no reasons defined — the Admin can add them under Setting → Poll Types.';
    }

    protected static function optionsPreview(mixed $typeId, ?Poll $record): string
    {
        $options = $record?->exists
            ? $record->optionList()
            : (filled($typeId) ? PollType::query()->find((int) $typeId)?->optionList() ?? [] : []);

        if ($options === []) {
            return '<span class="text-gray-500">Pick a type of voting to see its options.</span>';
        }

        return collect($options)
            ->map(fn (string $option): string => '<span class="fi-badge inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">'.e($option).'</span>')
            ->implode(' ');
    }
}
