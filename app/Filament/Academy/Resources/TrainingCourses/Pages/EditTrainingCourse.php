<?php

namespace App\Filament\Academy\Resources\TrainingCourses\Pages;

use App\Filament\Academy\Resources\TrainingCourses\TrainingCourseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingCourse extends EditRecord
{
    protected static string $resource = TrainingCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * tenant_id is never editable — the resource's tenant-scoped base
     * query is what stops another tenant's course being loaded here in
     * the first place, and this stops a saved payload moving one out.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['tenant_id']);

        return $data;
    }
}
