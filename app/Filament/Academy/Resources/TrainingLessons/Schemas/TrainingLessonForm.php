<?php

namespace App\Filament\Academy\Resources\TrainingLessons\Schemas;

use App\Models\Training\TrainingModule;
use App\Support\Portal\PortalContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TrainingLessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lesson')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('training_module_id')
                            ->label('Module')
                            ->options(fn (): array => TrainingModule::query()
                                ->whereHas(
                                    'course',
                                    fn ($query) => $query->where('tenant_id', app(PortalContext::class)->tenantId())
                                )
                                ->with('course')
                                ->get()
                                ->mapWithKeys(fn (TrainingModule $module): array => [
                                    $module->getKey() => ($module->course?->title.' — '.$module->title),
                                ])
                                ->all())
                            ->required()
                            ->preload()
                            ->columnSpanFull(),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Textarea::make('summary')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
                ])
                ->columnSpanFull(),

            Section::make('Content')
                ->schema([
                    Select::make('content_type')
                        ->options([
                            'text' => 'Reading material',
                            'video' => 'Video',
                            'mixed' => 'Video + reading',
                        ])
                        ->default('text')
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('video_url')
                        ->label('Video URL')
                        ->url()
                        ->maxLength(2048)
                        ->helperText('Direct link to an MP4 or a hosted stream.')
                        ->visible(fn (Get $get): bool => in_array($get('content_type'), ['video', 'mixed'], true))
                        ->columnSpanFull(),

                    Textarea::make('content')
                        ->rows(10)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make('Settings')
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->minValue(0)
                            ->default(10),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        Toggle::make('is_required')
                            ->label('Required')
                            ->default(true),

                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                            ])
                            ->default('published')
                            ->required(),
                    ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
