<?php

namespace App\Filament\Academy\Resources\TrainingCourses\Pages;

use App\Filament\Academy\Resources\TrainingCourses\TrainingCourseResource;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalContext;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingCourse extends CreateRecord
{
    protected static string $resource = TrainingCourseResource::class;

    /**
     * tenant_id and created_by are stamped here rather than exposed as
     * form fields — a trainer must not be able to author a course into
     * another tenant by editing the payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(PortalContext::class)->tenantId();
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        PortalAudit::courseCreated($this->getRecord());
    }
}
