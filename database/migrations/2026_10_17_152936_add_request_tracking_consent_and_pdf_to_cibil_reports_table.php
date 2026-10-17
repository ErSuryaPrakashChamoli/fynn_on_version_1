<?php

use App\Support\CibilAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The live Surepass bureau answers three kinds of request — score, full
 * report, PDF — so each row now says which it was, how the provider
 * answered (HTTP status, message code), when it completed, how the
 * customer's consent was obtained, where its PDF is kept, and — for a
 * request whose outcome is uncertain — who verified it. Existing rows were
 * full-report pulls. Nothing is dropped and no uniqueness is added, so the
 * report history stays complete.
 *
 * The finer CIBIL permissions are created here too. Whoever could already
 * fetch reports keeps reading full reports (cibil.full_report); PDF, fresh
 * pulls and the audit history start with the Admin only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->string('request_type', 20)->default('full_report')->after('customer_assignment_id');
            $table->string('request_reason', 500)->nullable()->after('request_type');

            $table->unsignedSmallInteger('provider_status_code')->nullable()->after('bureau_reference');
            $table->string('provider_message_code', 100)->nullable()->after('provider_status_code');

            // Signed provider link, encrypted, kept only until the file is saved.
            $table->string('pdf_status', 20)->nullable()->after('response_payload');
            $table->string('pdf_path')->nullable()->after('pdf_status');
            $table->text('pdf_link')->nullable()->after('pdf_path');

            $table->string('consent_purpose')->nullable()->after('consent_given_at');
            $table->string('consent_method', 30)->nullable()->after('consent_purpose');
            $table->string('consent_reference')->nullable()->after('consent_method');

            $table->timestamp('completed_at')->nullable()->after('fetched_at');
            $table->timestamp('resolved_at')->nullable()->after('completed_at');
            // Kept when the login is deleted: the verification still happened.
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable()->after('resolved_by');

            // In-flight / uncertain checks look a PAN up by request type and status.
            $table->index(['pan_number', 'request_type', 'status'], 'cibil_reports_pan_type_status_index');
        });

        DB::table('cibil_reports')
            ->where('status', '!=', 'pending')
            ->update(['completed_at' => DB::raw('COALESCE(fetched_at, updated_at)')]);

        $fetch = Permission::findOrCreate(CibilAccess::PERMISSION, 'web');

        foreach (CibilAccess::EXTRA_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $fullReport = Permission::findOrCreate(CibilAccess::FULL_REPORT_PERMISSION, 'web');
        $fullReport->roles()->syncWithoutDetaching($fetch->roles()->pluck('id')->all());
        $fullReport->users()->syncWithoutDetaching($fetch->users()->pluck('users.id')->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->dropIndex('cibil_reports_pan_type_status_index');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'request_type',
                'request_reason',
                'provider_status_code',
                'provider_message_code',
                'pdf_status',
                'pdf_path',
                'pdf_link',
                'consent_purpose',
                'consent_method',
                'consent_reference',
                'completed_at',
                'resolved_at',
                'resolution_note',
            ]);
        });

        Permission::query()
            ->whereIn('name', CibilAccess::EXTRA_PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
