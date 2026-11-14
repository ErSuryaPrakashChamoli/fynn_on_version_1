<?php

namespace App\Filament\Academy\Resources\TrainingCertificates\Pages;

use App\Filament\Academy\Resources\TrainingCertificates\TrainingCertificateResource;
use App\Filament\Concerns\HasColumnHeaderFilters;
use Filament\Resources\Pages\ListRecords;

class ListTrainingCertificates extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = TrainingCertificateResource::class;
}
