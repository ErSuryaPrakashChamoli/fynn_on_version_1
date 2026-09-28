<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Filament\Resources\Complaints\ComplaintActions;
use App\Filament\Resources\Complaints\ComplaintResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewComplaint extends ViewRecord
{
    protected static string $resource = ComplaintResource::class;

    public function getTitle(): string
    {
        return $this->record->ticket_no.' — '.$this->record->subject;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ComplaintActions::all(),
            EditAction::make(),
        ];
    }
}
