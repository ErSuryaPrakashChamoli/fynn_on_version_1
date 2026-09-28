<?php

use App\Models\PollType;
use Database\Seeders\VotingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reason dropdown for votes: the Admin defines reasons on a poll type
 * (per answer, or for any answer); whoever raises a poll toggles whether
 * to ask for one; the vote stores the reason picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poll_types', function (Blueprint $table): void {
            $table->json('reasons')->nullable()->after('options');
        });

        Schema::table('polls', function (Blueprint $table): void {
            $table->boolean('ask_reason')->default(false)->after('allow_comment');
            $table->json('reasons')->nullable()->after('ask_reason');
        });

        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->string('reason')->nullable()->after('option');
        });

        // Give the seeded Feedback type its default reasons where the Admin
        // has not defined any yet.
        foreach (VotingSeeder::defaultTypes() as $name => $definition) {
            $type = PollType::query()->where('name', $name)->first();

            if ($type && empty($type->reasons) && ! empty($definition['reasons'])) {
                $type->forceFill(['reasons' => $definition['reasons']])->save();
            }
        }
    }

    public function down(): void
    {
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });

        Schema::table('polls', function (Blueprint $table): void {
            $table->dropColumn(['ask_reason', 'reasons']);
        });

        Schema::table('poll_types', function (Blueprint $table): void {
            $table->dropColumn('reasons');
        });
    }
};
