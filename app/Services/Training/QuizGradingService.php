<?php

namespace App\Services\Training;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAnswer;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\User;
use App\Support\Portal\PortalAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts and grades quiz/assessment attempts.
 *
 * Grading happens entirely here, server side, against correct_answer
 * columns that TrainingQuizQuestion hides from serialization — the
 * browser is never sent the key, so a trainee reading their own Livewire
 * payload learns nothing.
 */
class QuizGradingService
{
    /**
     * Open a new attempt, refusing to exceed the quiz's attempt cap.
     *
     * @throws ValidationException when no attempts remain
     */
    public function start(TrainingQuiz $quiz, TrainingEnrollment $enrollment, User $trainee): TrainingQuizAttempt
    {
        $used = TrainingQuizAttempt::query()
            ->where('training_quiz_id', $quiz->getKey())
            ->where('training_enrollment_id', $enrollment->getKey())
            ->where('status', 'completed')
            ->count();

        if ($quiz->max_attempts > 0 && $used >= $quiz->max_attempts) {
            throw ValidationException::withMessages([
                'quiz' => "You have used all {$quiz->max_attempts} attempts for this quiz.",
            ]);
        }

        return TrainingQuizAttempt::create([
            'training_quiz_id' => $quiz->getKey(),
            'training_enrollment_id' => $enrollment->getKey(),
            'trainee_id' => $trainee->getKey(),
            'attempt_number' => $used + 1,
            'total_marks' => $quiz->totalMarks(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Grade a set of answers keyed by question id and close the attempt.
     *
     * @param  array<int, array<int, string>|string|null>  $answers
     */
    public function grade(TrainingQuizAttempt $attempt, array $answers): TrainingQuizAttempt
    {
        return DB::transaction(function () use ($attempt, $answers): TrainingQuizAttempt {
            $questions = $attempt->quiz->questions()->get();
            $score = 0;
            $totalMarks = 0;

            foreach ($questions as $question) {
                $totalMarks += $question->marks;
                $given = $answers[$question->getKey()] ?? null;
                $isCorrect = $question->isAnswerCorrect($given);
                $awarded = $isCorrect ? $question->marks : 0;
                $score += $awarded;

                TrainingQuizAnswer::updateOrCreate(
                    [
                        'training_quiz_attempt_id' => $attempt->getKey(),
                        'training_quiz_question_id' => $question->getKey(),
                    ],
                    [
                        'answer' => (array) $given,
                        'is_correct' => $isCorrect,
                        'marks_awarded' => $awarded,
                    ]
                );
            }

            $percentage = $totalMarks > 0 ? (int) round(($score / $totalMarks) * 100) : 0;

            $attempt->forceFill([
                'score' => $score,
                'total_marks' => $totalMarks,
                'percentage' => $percentage,
                'passed' => $percentage >= $attempt->quiz->pass_percentage,
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $attempt->refresh();

            if ($attempt->quiz->isAssessment()) {
                PortalAudit::assessmentGraded($attempt);
            }

            return $attempt;
        });
    }

    /**
     * How many attempts remain for this trainee on this quiz.
     */
    public function attemptsRemaining(TrainingQuiz $quiz, TrainingEnrollment $enrollment): int
    {
        if ($quiz->max_attempts <= 0) {
            return PHP_INT_MAX;
        }

        $used = TrainingQuizAttempt::query()
            ->where('training_quiz_id', $quiz->getKey())
            ->where('training_enrollment_id', $enrollment->getKey())
            ->where('status', 'completed')
            ->count();

        return max(0, $quiz->max_attempts - $used);
    }

    /**
     * The trainee's best completed attempt, which is what the trainer
     * dashboard and the certificate score read.
     */
    public function bestAttempt(TrainingQuiz $quiz, TrainingEnrollment $enrollment): ?TrainingQuizAttempt
    {
        return TrainingQuizAttempt::query()
            ->where('training_quiz_id', $quiz->getKey())
            ->where('training_enrollment_id', $enrollment->getKey())
            ->where('status', 'completed')
            ->orderByDesc('percentage')
            ->first();
    }
}
