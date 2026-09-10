<?php

namespace App\Services\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonProgress;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of enrollment/lesson progress.
 *
 * Progress is derived, never typed in: completing a lesson recomputes
 * the enrollment percentage from the actual rows, so the two can't drift
 * apart the way they would if each caller updated both.
 */
class TrainingProgressService
{
    /**
     * Mark one lesson complete for one enrollment and refresh the
     * enrollment's rolled-up percentage.
     */
    public function completeLesson(TrainingEnrollment $enrollment, TrainingLesson $lesson): TrainingLessonProgress
    {
        return DB::transaction(function () use ($enrollment, $lesson): TrainingLessonProgress {
            $progress = TrainingLessonProgress::updateOrCreate(
                [
                    'training_enrollment_id' => $enrollment->getKey(),
                    'training_lesson_id' => $lesson->getKey(),
                ],
                [
                    'status' => 'completed',
                    'progress_percentage' => 100,
                    'completed_at' => now(),
                ]
            );

            if ($progress->started_at === null) {
                $progress->forceFill(['started_at' => now()])->save();
            }

            $this->recalculate($enrollment);

            return $progress;
        });
    }

    public function startLesson(TrainingEnrollment $enrollment, TrainingLesson $lesson): TrainingLessonProgress
    {
        $progress = TrainingLessonProgress::firstOrCreate(
            [
                'training_enrollment_id' => $enrollment->getKey(),
                'training_lesson_id' => $lesson->getKey(),
            ],
            [
                'status' => 'in_progress',
                'started_at' => now(),
            ]
        );

        if ($enrollment->started_at === null) {
            $enrollment->forceFill([
                'started_at' => now(),
                'status' => 'in_progress',
            ])->save();
        }

        return $progress;
    }

    /**
     * Recompute progress_percentage and the enrollment status from the
     * lesson rows. Returns the new percentage.
     */
    public function recalculate(TrainingEnrollment $enrollment): int
    {
        $total = $this->totalLessons($enrollment);

        if ($total === 0) {
            return 0;
        }

        $completed = $enrollment->lessonProgress()
            ->where('status', 'completed')
            ->count();

        $percentage = (int) min(100, round(($completed / $total) * 100));

        $attributes = ['progress_percentage' => $percentage];

        if ($percentage >= 100 && $enrollment->status !== 'completed') {
            $attributes['status'] = 'completed';
            $attributes['completed_at'] = now();
        } elseif ($percentage > 0 && $enrollment->status === 'assigned') {
            $attributes['status'] = 'in_progress';
            $attributes['started_at'] = $enrollment->started_at ?? now();
        }

        $enrollment->forceFill($attributes)->save();

        return $percentage;
    }

    public function totalLessons(TrainingEnrollment $enrollment): int
    {
        return TrainingLesson::query()
            ->whereHas(
                'module',
                fn ($query) => $query->where('training_course_id', $enrollment->training_course_id)
            )
            ->where('status', 'published')
            ->count();
    }

    /**
     * The next lesson the trainee has not completed, in course order.
     */
    public function nextLesson(TrainingEnrollment $enrollment): ?TrainingLesson
    {
        $completedIds = $enrollment->lessonProgress()
            ->where('status', 'completed')
            ->pluck('training_lesson_id');

        return TrainingLesson::query()
            ->select('training_lessons.*')
            ->join('training_modules', 'training_modules.id', '=', 'training_lessons.training_module_id')
            ->where('training_modules.training_course_id', $enrollment->training_course_id)
            ->where('training_lessons.status', 'published')
            ->whereNotIn('training_lessons.id', $completedIds)
            ->orderBy('training_modules.sort_order')
            ->orderBy('training_lessons.sort_order')
            ->first();
    }
}
