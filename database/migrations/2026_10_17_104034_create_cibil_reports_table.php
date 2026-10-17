<?php

use App\Support\CibilAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * One row per CIBIL pull. The applicant's basic details are snapshotted on
 * the row (the customer file can change, the report was fetched for these
 * details); customer_id links it back to a customer file when the pull was
 * started from one. The bureau's full response is stored encrypted.
 *
 * The cibil.fetch permission is created here so the Admin's access screen
 * works on every environment the moment the migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cibil_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Kept when the login is deleted: the pull still happened.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name');
            $table->string('pan_number', 10)->index();
            $table->string('mobile_no', 15);
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('pincode', 6)->nullable();

            $table->string('status')->default('pending')->index();
            $table->smallInteger('score')->nullable();
            $table->string('bureau_reference')->nullable();
            $table->longText('response_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('consent_given_at');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'created_at']);
        });

        Permission::findOrCreate(CibilAccess::PERMISSION, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('cibil_reports');

        Permission::query()
            ->where('name', CibilAccess::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
