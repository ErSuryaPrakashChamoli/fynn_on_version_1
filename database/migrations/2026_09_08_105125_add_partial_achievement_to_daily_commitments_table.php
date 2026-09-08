<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day's commitment can now be fulfilled in parts: ₹10L committed at
 * Approval may come back as ₹7L Approval + ₹3L SFL.
 *
 * achievement_amount keeps its existing meaning — business at or above
 * the committed stage, the only thing that earns a full pass — and the
 * two columns added here carry the rest of the declared business, which
 * is what turns an otherwise-Failed day into PARTIALLY MET.
 *
 * declaration_note is the reason attached to a nil day: the 6:30 pm
 * declaration is compulsory, so "nothing came through today" has to be a
 * statement the employee makes on the record rather than a silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commitments', function (Blueprint $table) {
            $table->decimal('below_stage_amount', 15, 2)->default(0)->after('achievement_count');
            $table->unsignedInteger('below_stage_count')->default(0)->after('below_stage_amount');
            $table->string('declaration_note', 500)->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commitments', function (Blueprint $table) {
            $table->dropColumn(['below_stage_amount', 'below_stage_count', 'declaration_note']);
        });
    }
};
