<?php

namespace Database\Seeders;

use App\Models\PollType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * The Voting module's starting poll types — the answer dropdowns the
 * Admin defines and every poll picks from. Safe to run repeatedly: it only
 * adds what is missing and never touches a type the Admin has edited.
 */
class VotingSeeder extends Seeder
{
    /**
     * @return array<string, array{description: string, options: list<string>, allow_comment: bool, reasons: list<array{option: string|null, reason: string}>}>
     */
    public static function defaultTypes(): array
    {
        return [
            'Feedback' => [
                'description' => 'How was it? Good, satisfactory or bad, with room for a comment.',
                'options' => ['Good', 'Satisfactory', 'Bad'],
                'allow_comment' => true,
                'reasons' => [
                    ['option' => 'Good', 'reason' => 'Quality'],
                    ['option' => 'Good', 'reason' => 'Speed'],
                    ['option' => 'Good', 'reason' => 'Behaviour / support'],
                    ['option' => 'Bad', 'reason' => 'Quality'],
                    ['option' => 'Bad', 'reason' => 'Delay'],
                    ['option' => 'Bad', 'reason' => 'Behaviour / support'],
                    ['option' => null, 'reason' => 'Other'],
                ],
            ],
            'Yes / No' => [
                'description' => 'A straight yes-or-no decision.',
                'options' => ['Yes', 'No'],
                'allow_comment' => false,
                'reasons' => [],
            ],
            'Agreement' => [
                'description' => 'How far people agree with a statement.',
                'options' => ['Strongly agree', 'Agree', 'Neutral', 'Disagree', 'Strongly disagree'],
                'allow_comment' => true,
                'reasons' => [],
            ],
            'Rating (1-5)' => [
                'description' => 'A five-point score, 5 being the best.',
                'options' => ['5 - Excellent', '4 - Good', '3 - Average', '2 - Poor', '1 - Very poor'],
                'allow_comment' => true,
                'reasons' => [],
            ],
        ];
    }

    public function run(): void
    {
        $order = 0;

        // The reasons column arrived in a later migration than the one that
        // first runs this seeder; that later migration backfills them.
        $hasReasons = Schema::hasColumn('poll_types', 'reasons');

        foreach (self::defaultTypes() as $name => $definition) {
            $order++;

            if (! $hasReasons) {
                unset($definition['reasons']);
            }

            PollType::query()->firstOrCreate(
                ['name' => $name],
                [...$definition, 'is_active' => true, 'sort_order' => $order],
            );
        }
    }
}
