<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateComplaint extends CreateRecord
{
    protected static string $resource = ComplaintResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * The service does the routing, the deadline, the thread and the
     * notifications — the page never writes the row itself.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Sign in to raise a ticket.');
        }

        try {
            return app(ComplaintService::class)->raise($user, $data);
        } catch (AuthorizationException $exception) {
            throw ValidationException::withMessages([
                'data.escalate_to' => $exception->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Ticket '.$this->record->ticket_no.' raised')
            ->body('Sent to '.$this->record->handlerLabel().'. Resolve by '.$this->record->due_at?->format('d M Y, h:i A').'.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
