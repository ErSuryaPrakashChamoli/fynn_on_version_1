<?php

namespace App\Filament\Resources\FollowUps\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFollowUps extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = FollowUpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),

            //  Action::make('createProspect')
            //     ->label('Create Prospect')
            //     ->color('warning')
            //     ->icon('heroicon-o-user-plus')
            //     // Fix: Laravel ka route use karke explicitly query string bhejein
            //     ->url(fn (): string => route('filament.admin.resources.follow-ups.create', ['mode' => 'prospect'])),

        ];
    }
}
