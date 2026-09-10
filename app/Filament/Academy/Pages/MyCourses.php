<?php

namespace App\Filament\Academy\Pages;

use App\Models\Training\TrainingEnrollment;
use App\Services\Training\TrainingProgressService;
use App\Support\Portal\PortalContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The trainee's course list.
 *
 * The query is `ownedBy(auth()->user())` with no request input of any
 * kind, so the page cannot be pointed at another trainee's courses.
 */
class MyCourses extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'My Courses';

    protected static ?string $title = 'My Courses';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'my-courses';

    protected string $view = 'filament.academy.pages.my-courses';

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEnrollments(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $progress = app(TrainingProgressService::class);

        return TrainingEnrollment::query()
            ->ownedBy($user)
            ->with(['course', 'batch'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (TrainingEnrollment $enrollment): array => [
                'enrollment' => $enrollment,
                'course' => $enrollment->course,
                'nextLesson' => $progress->nextLesson($enrollment),
                'totalLessons' => $progress->totalLessons($enrollment),
                'completedLessons' => $enrollment->lessonProgress()->where('status', 'completed')->count(),
            ])
            ->all();
    }
}
