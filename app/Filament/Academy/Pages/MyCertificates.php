<?php

namespace App\Filament\Academy\Pages;

use App\Models\Training\TrainingCertificate;
use App\Support\Portal\PortalContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class MyCertificates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'My Certificates';

    protected static ?string $title = 'My Certificates';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'my-certificates';

    protected string $view = 'filament.academy.pages.my-certificates';

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return Collection<int, TrainingCertificate>
     */
    public function getCertificates(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return new Collection;
        }

        return TrainingCertificate::query()
            ->ownedBy($user)
            ->with(['course', 'trainee'])
            ->latest('issued_on')
            ->get();
    }
}
