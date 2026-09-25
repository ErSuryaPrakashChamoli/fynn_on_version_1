<?php

use App\Enums\NotificationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('category', 40)->default(NotificationCategory::General->value)->after('type');
            $table->timestamp('remind_at')->nullable()->after('read_at');
            $table->string('resolution', 20)->nullable()->after('remind_at');
            $table->text('resolution_remarks')->nullable()->after('resolution');
            $table->timestamp('resolved_at')->nullable()->after('resolution_remarks');

            $table->index(['notifiable_type', 'notifiable_id', 'category'], 'notifications_notifiable_category_index');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('next_follow_up_date');
            $table->index('next_follow_up_date');
        });

        DB::table('notifications')
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunk(500, function ($notifications): void {
                foreach ($notifications as $notification) {
                    $category = NotificationCategory::fromNotificationData(json_decode($notification->data, true) ?: []);

                    if ($category !== NotificationCategory::General) {
                        DB::table('notifications')->where('id', $notification->id)->update(['category' => $category->value]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_category_index');
            $table->dropColumn(['category', 'remind_at', 'resolution', 'resolution_remarks', 'resolved_at']);
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex(['next_follow_up_date']);
            $table->dropColumn('reminded_at');
        });
    }
};
