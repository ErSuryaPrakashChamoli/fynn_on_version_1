<?php

use App\Models\ComplaintCategory;
use Database\Seeders\HelpDeskSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket form no longer asks for a subject (it is derived from the
 * reason), the description is optional, and a supervisor may raise a
 * ticket on behalf of somebody in their team (on_behalf_of). Also adds the workstation
 * reasons (mouse, keyboard, monitor, CPU, headphone, voice, dialer, slow
 * call flow) to the Desktop / Laptop category on databases that already
 * had it seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->string('subject')->nullable()->change();
            $table->text('description')->nullable()->change();
            $table->foreignId('on_behalf_of')->nullable()->after('raised_by')->constrained('users')->nullOnDelete();
        });

        $category = ComplaintCategory::query()->where('name', 'Desktop / Laptop')->first();

        if ($category) {
            $category->reasons()->where('name', 'Other')->update(['name' => 'Other issue']);
            HelpDeskSeeder::syncReasons($category, HelpDeskSeeder::defaultCategories()['Desktop / Laptop']['reasons']);
        }
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('on_behalf_of');
            $table->string('subject')->nullable(false)->change();
            $table->text('description')->nullable(false)->change();
        });
    }
};
