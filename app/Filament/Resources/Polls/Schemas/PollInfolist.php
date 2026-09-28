<?php

namespace App\Filament\Resources\Polls\Schemas;

use App\Models\Poll;
use App\Services\Voting\PollService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PollInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (Poll $record): string => $record->title)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('question')
                            ->hiddenLabel()
                            ->prose()
                            ->columnSpanFull(),

                        Grid::make(4)->schema([
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->state(fn (Poll $record): string => $record->statusLabel())
                                ->color(fn (Poll $record): string => $record->statusColor()),
                            TextEntry::make('type.name')->label('Type of voting'),
                            TextEntry::make('audience')
                                ->label('Sent to')
                                ->state(fn (Poll $record): string => $record->audienceLabel()),
                            TextEntry::make('participation')
                                ->label('Participation')
                                ->state(fn (Poll $record): string => $record->participationLabel()),
                        ]),

                        Grid::make(4)->schema([
                            TextEntry::make('is_mandatory')
                                ->label('Mandatory')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Mandatory' : 'Optional')
                                ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                            TextEntry::make('is_anonymous')
                                ->label('Anonymity')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Anonymous' : 'Named votes')
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('expires_at')->label('Closes at')->dateTime('d M Y, h:i A')->placeholder('Open until closed'),
                            TextEntry::make('creator.name')->label('Raised by')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Results')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('results')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Poll $record): HtmlString => new HtmlString(self::resultsHtml($record)))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function resultsHtml(Poll $record): string
    {
        $results = app(PollService::class)->results($record);

        if ($record->votes_count === 0) {
            return '<p class="text-sm text-gray-500">No votes yet.</p>';
        }

        $rows = $results->map(function (array $row): string {
            $width = max(2, (int) round($row['percent']));

            return sprintf(
                '<div class="mb-3"><div class="mb-1 flex items-center justify-between text-sm"><span class="font-medium text-gray-950 dark:text-white">%s</span><span class="text-gray-600 dark:text-gray-300">%d %s · %s%%</span></div><div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"><div class="h-full rounded-full bg-primary-500" style="width: %d%%"></div></div></div>',
                e($row['option']),
                $row['votes'],
                $row['votes'] === 1 ? 'vote' : 'votes',
                number_format($row['percent'], 1),
                $width,
            );
        })->implode('');

        $reasons = app(PollService::class)->reasonBreakdown($record);

        if ($reasons->isNotEmpty()) {
            $rows .= '<h4 class="mb-2 mt-4 text-sm font-semibold text-gray-950 dark:text-white">Reasons given</h4>';

            foreach ($reasons as $option => $counts) {
                $list = collect($counts)
                    ->map(fn (int $count, string $reason): string => e($reason).' <span class="text-gray-500">('.$count.')</span>')
                    ->implode(', ');
                $rows .= '<p class="text-sm text-gray-700 dark:text-gray-200"><span class="font-medium">'.e($option).':</span> '.$list.'</p>';
            }
        }

        return $rows.'<p class="mt-2 text-xs text-gray-500">'.e($record->participationLabel()).'</p>';
    }
}
