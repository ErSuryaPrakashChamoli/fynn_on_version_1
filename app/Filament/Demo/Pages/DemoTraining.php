<?php

namespace App\Filament\Demo\Pages;

use App\Models\Training\TrainingCourse;
use App\Support\Portal\PortalContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * A read-only shop window onto the training module.
 *
 * A prospect should see that FYNN-ON includes onboarding training, but
 * the Academy panel itself is not opened to them: this page reads the
 * DEMO tenant's own course rows (seeded by DemoDataSeeder), so nothing
 * an internal trainer authors for FynnEdge is ever visible here.
 */
class DemoTraining extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Training';

    protected static ?string $title = 'Training Programmes';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'training';

    protected string $view = 'filament.demo.pages.training';

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isDemo();
    }

    /**
     * @return Collection<int, TrainingCourse>
     */
    public function getCourses(): Collection
    {
        return TrainingCourse::query()
            ->where('tenant_id', app(PortalContext::class)->tenantId())
            ->withCount(['modules', 'enrollments'])
            ->orderBy('title')
            ->get();
    }
}
