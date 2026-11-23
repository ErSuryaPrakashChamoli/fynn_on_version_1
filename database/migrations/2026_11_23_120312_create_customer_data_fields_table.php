<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The saved fields an Admin ticks when uploading customer data from Excel or
 * CSV ("Import Data"), so a template no longer has to be built by hand for
 * every upload. A field added during an upload is saved here and offered
 * again next time; every column name a field was matched to is remembered
 * in aliases so the next file with that heading is ticked automatically.
 *
 * The keys of the starter fields are the canonical prospect keys the rest of
 * the app reads (AiRecordProspectFields), so leads uploaded this way show
 * their name, mobile, PAN and locations everywhere without extra mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_data_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('label', 150);
            $table->string('type', 30)->default('text');
            $table->json('aliases')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $starterFields = [
            ['customer_name', 'Customer Name', 'text', ['Name', 'Prospect Name', 'Applicant Name', 'Full Name', 'Client Name']],
            ['mobile_number', 'Mobile Number', 'mobile', ['Mobile', 'Mobile No', 'Phone', 'Phone Number', 'Contact', 'Contact No', 'Contact Number', 'Number']],
            ['pan_number', 'PAN Number', 'pan', ['PAN', 'PAN No', 'PAN Card', 'PAN Card No']],
            ['cibil_score', 'CIBIL Score', 'number', ['CIBIL', 'Cibil Score', 'Credit Score', 'Bureau Score', 'Score']],
            ['date_of_birth', 'Date of Birth', 'date', ['DOB', 'D.O.B', 'Birth Date', 'Date Birth']],
            ['residence_location', 'Residence Location', 'text', ['Residence', 'Residence City', 'Home City', 'Residential Location']],
            ['job_location', 'Job Location', 'text', ['Office Location', 'Work Location', 'Job City', 'Office City']],
            ['company_name', 'Company Name', 'text', ['Company', 'Employer', 'Employer Name', 'Organisation', 'Organization']],
            ['email', 'Email', 'email', ['Email ID', 'Email Address', 'Mail', 'Mail ID']],
            ['salary', 'Salary', 'number', ['Net Salary', 'Monthly Salary', 'Income', 'Monthly Income']],
        ];

        $now = now();

        DB::table('customer_data_fields')->insert(array_map(
            fn (array $field, int $index): array => [
                'key' => $field[0],
                'label' => $field[1],
                'type' => $field[2],
                'aliases' => json_encode($field[3]),
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $starterFields,
            array_keys($starterFields),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_data_fields');
    }
};
