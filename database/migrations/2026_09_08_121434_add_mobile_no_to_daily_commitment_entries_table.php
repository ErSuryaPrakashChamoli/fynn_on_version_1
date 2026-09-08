<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every case declared against a commitment now has to carry the
 * customer's mobile number, and a number may only ever be claimed once.
 *
 * The number is the identity of the case here — a name can be typed two
 * ways and an LMS id may be missing, but the mobile is the one thing that
 * pins a declaration to a real person. The unique index is what makes
 * "claimed once, by one person" true rather than merely intended: two
 * employees cannot both count the same customer, and the same customer
 * cannot be counted again on a later day.
 *
 * Nullable on purpose: a nil day declares no cases at all and asks for no
 * number, and the rows that already existed before this rule have none to
 * backfill (MySQL allows many NULLs under a unique index). New rows are
 * required to carry one by MyDailyCommitment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commitment_entries', function (Blueprint $table) {
            $table->string('mobile_no', 20)->nullable()->after('customer_name');
            $table->unique('mobile_no', 'daily_commitment_entries_mobile_no_unique');
        });

        $this->backfillFromLinkedCustomers();
    }

    public function down(): void
    {
        Schema::table('daily_commitment_entries', function (Blueprint $table) {
            $table->dropUnique('daily_commitment_entries_mobile_no_unique');
            $table->dropColumn('mobile_no');
        });
    }

    /**
     * Rows already linked to an LMS case inherit that case's mobile, so
     * existing declarations start out identified rather than blank. A
     * number already taken by an earlier row is skipped rather than
     * forced — the unique index has to hold, and the older claim wins.
     */
    private function backfillFromLinkedCustomers(): void
    {
        $seen = [];

        DB::table('daily_commitment_entries')
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->get(['id', 'customer_id'])
            ->each(function ($entry) use (&$seen): void {
                $mobile = DB::table('customers')->where('id', $entry->customer_id)->value('mobile_no');

                $mobile = $mobile === null ? null : preg_replace('/\D+/', '', (string) $mobile);

                if (blank($mobile) || isset($seen[$mobile])) {
                    return;
                }

                $seen[$mobile] = true;

                DB::table('daily_commitment_entries')
                    ->where('id', $entry->id)
                    ->update(['mobile_no' => $mobile]);
            });
    }
};
