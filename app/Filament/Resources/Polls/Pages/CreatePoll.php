<?php

namespace App\Filament\Resources\Polls\Pages;

use App\Filament\Resources\Polls\PollResource;
use App\Models\Poll;
use App\Models\PollType;
use App\Services\Voting\PollService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePoll extends CreateRecord
{
    protected static string $resource = PollResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * The options (and, when asked for, the reasons) are copied from the
     * type at creation so a later edit of the type never rewrites a
     * running poll.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = PollType::query()->findOrFail((int) $data['poll_type_id']);

        $data['options'] = $type->optionList();
        $data['allow_comment'] = (bool) ($data['allow_comment'] ?? true) && $type->allow_comment;
        $data['ask_reason'] = (bool) ($data['ask_reason'] ?? false) && $type->hasReasons();
        $data['reasons'] = $data['ask_reason'] ? $type->reasonList() : null;
        $data['created_by'] = Filament::auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Poll $poll */
        $poll = $this->record;

        app(PollService::class)->publish($poll);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Poll sent')
            ->body('Sent to '.$this->record->recipients_count.' users.'.($this->record->is_mandatory ? ' They must vote before carrying on.' : ''));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
