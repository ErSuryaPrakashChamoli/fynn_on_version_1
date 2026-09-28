<?php

use App\Models\ComplaintCategory;
use Database\Seeders\HelpDeskSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the "Customer Journey" reason to the Fynn-On Application category
 * on databases that already had it seeded. Existing reasons are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $category = ComplaintCategory::query()->where('name', 'Fynn-On Application')->first();

        if ($category) {
            HelpDeskSeeder::syncReasons($category, HelpDeskSeeder::defaultCategories()['Fynn-On Application']['reasons']);
        }
    }

    public function down(): void
    {
        ComplaintCategory::query()->where('name', 'Fynn-On Application')->first()
            ?->reasons()->where('name', 'Customer Journey')->delete();
    }
};
