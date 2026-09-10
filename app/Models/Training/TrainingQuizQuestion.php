<?php

namespace App\Models\Training;

use Database\Factories\Training\TrainingQuizQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingQuizQuestion extends Model
{
    /** @use HasFactory<TrainingQuizQuestionFactory> */
    use HasFactory;

    /**
     * Never expose the answer key through model serialization — the
     * trainee-facing quiz screen renders questions straight from these
     * models, and Livewire serializes public component state to the
     * browser.
     *
     * @var list<string>
     */
    protected $hidden = ['correct_answer', 'explanation'];

    protected $fillable = [
        'training_quiz_id',
        'question',
        'type',
        'options',
        'correct_answer',
        'explanation',
        'marks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_answer' => 'array',
            'marks' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(TrainingQuiz::class, 'training_quiz_id');
    }

    /**
     * @param  array<int, string>|string|null  $answer
     */
    public function isAnswerCorrect(array|string|null $answer): bool
    {
        $given = collect((array) $answer)->map(fn ($value) => (string) $value)->sort()->values()->all();
        $expected = collect($this->correct_answer)->map(fn ($value) => (string) $value)->sort()->values()->all();

        return $given === $expected && $given !== [];
    }
}
