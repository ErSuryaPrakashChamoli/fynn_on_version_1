<?php

namespace App\Filament\Academy\Resources\TrainingModules\Schemas;

use App\Models\Training\TrainingCourse;
use App\Support\Portal\PortalContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TrainingModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * The option list is tenant-scoped, so the dropdown cannot
             * even name another tenant's course — and a hand-crafted
             * payload still fails the exists rule for the same reason.
             */
            Select::make('training_course_id')
                ->label('Course')
                ->options(fn (): array => TrainingCourse::query()
                    ->where('tenant_id', app(PortalContext::class)->tenantId())
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->all())
                ->required()
                ->preload()
                ->columnSpanFull(),

            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->required(),

            Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                ])
                ->default('published')
                ->required(),
        ]);
    }
}
