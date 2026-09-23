<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The top of the funnel: leads and their follow-up logs, customer
 * follow-ups, the AI document pipeline (schemas, OCR uploads, extracted
 * records), bulk assignments to callers, and PAN duplicate requests.
 *
 * Every follow-up log is inserted oldest first, because the app treats
 * the highest id per prospect as its current state.
 */
class LeadSeeder extends Seeder
{
    private const LEAD_STATUSES = ['Pending' => 28, 'Interested' => 22, 'Busy' => 14, 'Not Interested' => 12, 'Not Eligible' => 10, 'No Response' => 9, 'Eligible for Other Bank' => 5];

    private const CUSTOMER_FOLLOW_UP_STATUSES = ['Journey Started' => 26, 'Interested' => 14, 'Awaiting Low ROI' => 12, 'Awaiting PF Waiver' => 10, 'Busy' => 8, 'Delay Multifunding' => 8, 'On Hold' => 7, 'Out of Station' => 6, 'Converted' => 5, 'Lost' => 4];

    private const TERMINAL = ['Not Interested', 'Not Eligible', 'Converted', 'Lost'];

    private const REMARKS = [
        'Pending' => ['Called, customer will confirm after discussing with family.', 'Shared eligibility details on WhatsApp.', 'Customer asked to call back in the evening.'],
        'Interested' => ['Interested in 5L personal loan, sharing documents.', 'Customer wants lowest ROI, comparing offers.', 'Interested, needs loan for home renovation.', 'Ready to proceed, sending document checklist.'],
        'Busy' => ['Customer busy in meeting, call back later.', 'Driving, asked to call after 6 PM.', 'Line busy twice.'],
        'Not Interested' => ['Already took a loan elsewhere.', 'Not looking for a loan right now.', 'Found ROI too high.'],
        'Not Eligible' => ['CIBIL below 650.', 'Salary below bank norms.', 'Company not listed with any partner bank.'],
        'No Response' => ['No answer, left WhatsApp message.', 'Phone switched off.', 'Rang out, will retry tomorrow.'],
        'Eligible for Other Bank' => ['Not eligible with BFL, profile fits HDFC.', 'Routed to partner bank — better match on salary.'],
        'Journey Started' => ['Login done, documents being verified.', 'Customer shared all KYC documents.', 'File moving with credit.'],
        'Awaiting Low ROI' => ['Customer waiting for a lower ROI offer.', 'Asked bank for a rate revision.'],
        'Awaiting PF Waiver' => ['Customer wants processing fee waived.', 'PF waiver request raised with bank.'],
        'Delay Multifunding' => ['Customer applied with two lenders, deciding.', 'Waiting on the other lender’s offer.'],
        'On Hold' => ['Customer asked to hold for two weeks.', 'On hold — travel plans.'],
        'Out of Station' => ['Customer out of station till next week.', 'Travelling, will share documents on return.'],
        'Converted' => ['Converted — loan disbursed.', 'Closed successfully.'],
        'Lost' => ['Customer went with another lender.', 'Customer dropped the plan.'],
    ];

    private DemoWorld $world;

    /** @var array<int, array<string, mixed>> */
    private array $followUps = [];

    private int $adminUserId;

    public function run(DemoWorld $world): void
    {
        $this->world = $world;
        $this->adminUserId = $world->personaUserIds['admin'];

        $this->seedLeads();
        $this->seedCustomerFollowUps();
        $this->flushFollowUps();

        $schemaId = $this->seedSchemas();
        $recordIds = $this->seedDocumentsAndRecords($schemaId);
        $this->seedAssignments($recordIds);
        $this->flushFollowUps();

        $this->seedPanRequests();
    }

    private function seedLeads(): void
    {
        $owners = $this->world->employeesWith(Employee::DESIGNATION_CALLER, activeOnly: false)
            ->merge($this->world->employeesWith(Employee::DESIGNATION_TEAM_LEADER));

        $leads = [];

        foreach ($owners as $owner) {
            $isCaller = (int) $owner->designation === Employee::DESIGNATION_CALLER;

            foreach ([2 => 12, 1 => 14, 0 => 20] as $monthOffset => $perMonth) {
                $from = Carbon::parse($owner->doj)->max($this->world->monthStart($monthOffset));
                $to = $this->world->monthEndOrToday($monthOffset);

                if ($owner->exit_status === 'yes' && $owner->exit_date) {
                    $to = $to->min(Carbon::parse($owner->exit_date));
                }

                if ($from->greaterThan($to)) {
                    continue;
                }

                $count = $isCaller ? $perMonth + mt_rand(-3, 4) : (int) ($perMonth / 3);

                for ($i = 0; $i < $count; $i++) {
                    $leads[] = $this->makeLead($owner, $this->randomDay($from, $to), $monthOffset === 0 && $owner->exit_status === 'no');
                }
            }
        }

        // Converted leads: the lead that became each of a slice of customers.
        $customers = DB::table('customers')->inRandomOrder()->limit(420)->get();

        foreach ($customers as $customer) {
            $createdAt = Carbon::parse($customer->created_at)->subDays(mt_rand(1, 6))->setTime(mt_rand(10, 18), mt_rand(0, 59));
            $leads[] = [
                'row' => [
                    'employee_id' => $customer->employee_id,
                    'customer_name' => $customer->customer_name,
                    'mobile_no' => $customer->mobile_no,
                    'email' => $customer->email,
                    'pan_number' => $customer->pan_number,
                    'current_location' => $customer->current_location,
                    'job_location' => $customer->job_location,
                    'residence_location' => $customer->residence_location,
                    'salary' => $customer->salary,
                    'follow_up_type' => 'Call',
                    'status' => 'Interested',
                    'next_follow_up_date' => Carbon::parse($customer->created_at)->setTime(11, 0),
                    'remarks' => 'Interested, documents received — converting to customer.',
                    'is_converted' => true,
                    'converted_customer_id' => $customer->id,
                    'bank_id' => null,
                    'created_at' => $createdAt,
                    'updated_at' => Carbon::parse($customer->created_at),
                ],
                'log' => [
                    ['Pending', $createdAt, $createdAt->copy()->addDay()->setTime(11, 0)],
                    ['Interested', $createdAt->copy()->addDay()->setTime(mt_rand(10, 13), mt_rand(0, 59)), Carbon::parse($customer->created_at)->setTime(11, 0)],
                ],
            ];
        }

        usort($leads, fn (array $a, array $b): int => $a['row']['created_at'] <=> $b['row']['created_at']);

        $this->world->insert('leads', array_column($leads, 'row'));

        $ids = DB::table('leads')->orderBy('id')->pluck('id')->all();

        foreach ($leads as $index => $lead) {
            foreach ($lead['log'] as [$status, $at, $next]) {
                $this->queueFollowUp(['lead_id' => $ids[$index]], $lead['row']['employee_id'], $status, $at, $next, $lead['row']['bank_id']);
            }
        }
    }

    /**
     * @return array{row: array<string, mixed>, log: array<int, array{0: string, 1: Carbon, 2: ?Carbon}>}
     */
    private function makeLead(object $owner, Carbon $day, bool $isLive): array
    {
        $first = $this->world->faker->firstName();
        $last = $this->world->faker->lastName();
        $city = $this->world->pick($this->world->cities);
        $createdAt = $this->world->timeOn($day);
        $status = $this->world->weighted(self::LEAD_STATUSES);
        $bankId = $status === 'Eligible for Other Bank' ? $this->bankIdFor($this->world->pick(DemoWorld::OTHER_BANKS)) : null;

        // Build the call log that led to this status, oldest first.
        $steps = $status === 'Pending' ? 1 : mt_rand(1, 3);
        $log = [];
        $at = $createdAt->copy();

        for ($step = 1; $step <= $steps; $step++) {
            $isLast = $step === $steps;
            $stepStatus = $isLast ? $status : $this->world->pick(['Pending', 'Busy', 'No Response']);
            $at = $step === 1 ? $at : $this->world->timeOn($at->copy()->addDays(mt_rand(1, 3))->min($this->world->lastBusinessDay()));
            $next = in_array($stepStatus, self::TERMINAL, true) ? null : $this->nextFollowUp($at, $isLive && $isLast);
            $log[] = [$stepStatus, $at->copy(), $next];
        }

        [$lastStatus, $lastAt, $lastNext] = end($log);

        return [
            'row' => [
                'employee_id' => $owner->id,
                'customer_name' => "{$first} {$last}",
                'mobile_no' => $this->world->mobile(),
                'email' => $this->world->chance(0.6) ? $this->world->email("{$first} {$last}", 'gmail.com') : null,
                'pan_number' => $this->world->chance(0.45) ? $this->world->pan($last) : null,
                'current_location' => $city,
                'job_location' => $this->world->chance(0.7) ? $city : null,
                'residence_location' => $this->world->chance(0.5) ? $city : null,
                'salary' => $this->world->chance(0.7) ? $this->world->amount(22000, 160000, 500) : null,
                'follow_up_type' => $this->world->weighted(['Call' => 74, 'WhatsApp' => 22, 'Email' => 2, 'Visit' => 2]),
                'status' => $lastStatus,
                'next_follow_up_date' => $lastNext,
                'remarks' => $this->world->pick(self::REMARKS[$lastStatus]),
                'is_converted' => false,
                'converted_customer_id' => null,
                'bank_id' => $bankId,
                'created_at' => $createdAt,
                'updated_at' => $lastAt,
            ],
            'log' => $log,
        ];
    }

    /**
     * Customers still in play get a follow-up log of their own ("My
     * Customer Follow-ups" and the customer calendar).
     */
    private function seedCustomerFollowUps(): void
    {
        $customers = DB::table('customers')
            ->where(function ($query): void {
                $query->whereIn('journey_status', ['sfl', 'underwriting', 'approved'])
                    ->orWhere(fn ($q) => $q->where('disbursal_status', 'disbursed')->where('disbursal_date', '>=', $this->world->monthStart()->toDateString()));
            })
            ->get();

        foreach ($customers as $customer) {
            $isOpen = $customer->disbursal_status !== 'disbursed';
            $at = Carbon::parse($customer->created_at)->max($this->world->monthStart());
            $steps = mt_rand(1, 3);

            for ($step = 1; $step <= $steps; $step++) {
                $isLast = $step === $steps;
                $status = ! $isOpen && $isLast ? 'Converted' : $this->world->weighted(array_diff_key(self::CUSTOMER_FOLLOW_UP_STATUSES, ['Converted' => 0, 'Lost' => 0]));
                $at = $step === 1 ? $this->world->timeOn($at) : $this->world->timeOn($at->copy()->addDays(mt_rand(1, 2))->min($this->world->lastBusinessDay()));
                $next = in_array($status, self::TERMINAL, true) ? null : $this->nextFollowUp($at, $isLast && $isOpen);

                $this->queueFollowUp(['customer_id' => $customer->id], $customer->employee_id, $status, $at, $next, null);
            }
        }
    }

    private function seedSchemas(): int
    {
        $field = fn (string $key, string $label, string $type, bool $required, array $aliases = []): array => compact('key', 'label', 'aliases', 'type', 'required');

        $leadSheet = DB::table('ai_document_schemas')->insertGetId([
            'name' => 'Customer Lead Sheet',
            'description' => 'Walk-in and camp lead sheets: one customer per row.',
            'fields' => json_encode([
                $field('customer_name', 'Customer Name', 'text', true, ['Applicant Name', 'Name']),
                $field('mobile_number', 'Mobile Number', 'mobile', true, ['Mobile', 'Phone', 'Contact No']),
                $field('pan_number', 'PAN Number', 'pan', false, ['PAN']),
                $field('email', 'Email', 'email', false, ['Email ID']),
                $field('salary', 'Monthly Salary', 'number', false, ['Net Salary', 'Income']),
                $field('company_name', 'Company Name', 'text', false, ['Employer', 'Company']),
                $field('current_location', 'Current Location', 'text', false, ['City']),
                $field('job_location', 'Job Location', 'text', false, ['Office Location']),
                $field('residence_location', 'Residence Location', 'text', false, ['Residence']),
                $field('product_type', 'Product', 'text', false, ['Loan Type']),
            ]),
            'is_active' => true,
            'created_by' => $this->adminUserId,
            'created_at' => $this->world->monthStart(3),
            'updated_at' => $this->world->monthStart(3),
        ]);

        DB::table('ai_document_schemas')->insert([
            'name' => 'Salary Slip Summary',
            'description' => 'Key figures read from a salary slip.',
            'fields' => json_encode([
                $field('customer_name', 'Employee Name', 'text', true, ['Name']),
                $field('company_name', 'Employer', 'text', true, ['Company']),
                $field('salary', 'Net Pay', 'decimal', true, ['Net Salary', 'Take Home']),
                $field('pay_month', 'Pay Month', 'date', false, ['Month']),
            ]),
            'is_active' => false,
            'created_by' => $this->adminUserId,
            'created_at' => $this->world->monthStart(2),
            'updated_at' => $this->world->monthStart(2),
        ]);

        return $leadSheet;
    }

    /**
     * @return array<int, int> ids of records usable for assignment
     */
    private function seedDocumentsAndRecords(int $schemaId): array
    {
        $samplePath = 'ocr-documents/demo-lead-sheet.pdf';
        Storage::disk('local')->put($samplePath, $this->world->samplePdf('Customer Lead Sheet'));

        $documents = [
            ['Noida walk-in camp — lead sheet', 'completed', 0],
            ['Lucknow corporate drive — lead sheet', 'completed', 0],
            ['Pune IT park camp — lead sheet', 'completed', 0],
            ['Referral list from partner DSA', 'completed', 1],
            ['Gurugram society camp — lead sheet', 'completed', 1],
            ['HCL campus drive — lead sheet', 'processing', 0],
            ['Weekend camp — handwritten sheet', 'failed', 0],
            ['Scanned referral register', 'pending', 0],
        ];

        $recordIds = [];

        foreach ($documents as [$title, $status, $monthOffset]) {
            $uploadedAt = $this->world->timeOn($this->randomDay($this->world->monthStart($monthOffset), $this->world->monthEndOrToday($monthOffset)));
            $rows = $status === 'completed' ? mt_rand(6, 14) : 0;

            $documentId = DB::table('ocr_documents')->insertGetId([
                'uploaded_by' => $this->world->personaUserIds['mis'],
                'title' => $title,
                'document_type' => 'customer_form',
                'schema_id' => $schemaId,
                'original_path' => $samplePath,
                'original_name' => str($title)->slug().'.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => mt_rand(180000, 2400000),
                'page_count' => $status === 'completed' ? mt_rand(1, 4) : null,
                'status' => $status,
                'ocr_text' => $status === 'completed' ? "Customer Lead Sheet\nName | Mobile | PAN | Salary | Company | City\n…" : null,
                'extracted_data' => $status === 'completed' ? json_encode(['mode' => 'table', 'headers' => [['Name', 'Mobile', 'PAN', 'Salary', 'Company', 'City']], 'rows' => []]) : null,
                'page_data' => $status === 'completed' ? json_encode([['page' => 1, 'rows' => $rows]]) : null,
                'confidence_score' => $status === 'completed' ? mt_rand(8200, 9700) / 10000 : null,
                'error_message' => match ($status) {
                    'failed' => 'Handwriting could not be read reliably on 2 of 3 pages. Please upload a clearer scan.',
                    'pending' => 'Queued for processing.',
                    default => null,
                },
                'processed_at' => in_array($status, ['completed', 'failed'], true) ? $uploadedAt->copy()->addMinutes(mt_rand(3, 25)) : null,
                'created_at' => $uploadedAt,
                'updated_at' => $uploadedAt,
            ]);

            for ($r = 0; $r < $rows; $r++) {
                $recordIds[] = $this->makeRecord($schemaId, $documentId, $uploadedAt->copy()->addMinutes(mt_rand(5, 30)));
            }
        }

        // Imported spreadsheets land as records with no source document.
        foreach ([1 => 30, 0 => 60] as $monthOffset => $count) {
            for ($i = 0; $i < $count; $i++) {
                $recordIds[] = $this->makeRecord($schemaId, null, $this->world->timeOn($this->randomDay($this->world->monthStart($monthOffset), $this->world->monthEndOrToday($monthOffset))));
            }
        }

        // A few duplicates of earlier records (same mobile number).
        $originals = DB::table('ai_customer_records')->where('is_duplicate', false)->inRandomOrder()->limit(6)->get();

        foreach ($originals as $original) {
            $at = Carbon::parse($original->created_at)->addDays(mt_rand(1, 10))->min($this->world->now);

            DB::table('ai_customer_records')->insert([
                'schema_id' => $schemaId,
                'data' => $original->data,
                'status' => 'review',
                'confidence_score' => mt_rand(8000, 9500) / 10000,
                'is_duplicate' => true,
                'duplicate_of_id' => $original->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $recordIds;
    }

    private function makeRecord(int $schemaId, ?int $documentId, Carbon $at): int
    {
        $first = $this->world->faker->firstName();
        $last = $this->world->faker->lastName();
        $city = $this->world->pick($this->world->cities);
        $status = $this->world->weighted(['review' => 48, 'approved' => 32, 'rejected' => 12, 'pending' => 8]);

        return DB::table('ai_customer_records')->insertGetId([
            'schema_id' => $schemaId,
            'ocr_document_id' => $documentId,
            'data' => json_encode([
                'customer_name' => "{$first} {$last}",
                'mobile_number' => $this->world->mobile(),
                'pan_number' => $this->world->chance(0.6) ? $this->world->pan($last) : null,
                'email' => $this->world->chance(0.5) ? $this->world->email("{$first} {$last}", 'gmail.com') : null,
                'salary' => (string) $this->world->amount(25000, 150000, 1000),
                'company_name' => $this->world->pick(DemoWorld::COMPANIES),
                'current_location' => $city,
                'job_location' => $city,
                'residence_location' => $this->world->chance(0.7) ? $city : $this->world->pick($this->world->cities),
                'product_type' => $this->world->weighted(['Personal Loan' => 80, 'Overdraft' => 12, 'Business Loan' => 8]),
            ]),
            'status' => $status,
            'confidence_score' => $documentId ? mt_rand(7800, 9900) / 10000 : null,
            'reviewed_by' => in_array($status, ['approved', 'rejected'], true) ? $this->world->personaUserIds['mis'] : null,
            'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? $at->copy()->addHours(mt_rand(1, 20))->min($this->world->now) : null,
            'rejection_reason' => $status === 'rejected' ? $this->world->pick(['Mobile number unreadable.', 'Duplicate of an existing customer.', 'Incomplete row — name missing.']) : null,
            'is_duplicate' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * @param  array<int, int>  $recordIds
     */
    private function seedAssignments(array $recordIds): void
    {
        $assignees = $this->world->employeesWith(Employee::DESIGNATION_CALLER)->values();
        $assignable = DB::table('ai_customer_records')->whereIn('id', $recordIds)->whereIn('status', ['approved', 'review'])->where('created_at', '>=', $this->world->monthStart(1))->get()->shuffle()->take(110)->values();
        $customers = DB::table('customers')->where('created_at', '>=', $this->world->monthStart())->inRandomOrder()->limit(70)->get();
        $assignedBy = $this->world->personaEmployeeIds['mis'];

        $targets = [
            ...$assignable->map(fn (object $record): array => ['ai_customer_record_id' => $record->id, 'customer_id' => null, 'employee_id' => $assignees->random()->id, 'at' => Carbon::parse($record->created_at)->addHours(mt_rand(2, 30))->min($this->world->now)]),
            ...$customers->map(fn (object $customer): array => ['ai_customer_record_id' => null, 'customer_id' => $customer->id, 'employee_id' => $customer->employee_id, 'at' => Carbon::parse($customer->created_at)->subHours(mt_rand(2, 30))]),
        ];

        foreach (collect($targets)->groupBy('employee_id') as $employeeId => $group) {
            foreach ($group->chunk(mt_rand(4, 8)) as $batch) {
                $batchAt = $batch->min('at');
                $batchId = DB::table('customer_assignment_batches')->insertGetId([
                    'assigned_by' => $assignedBy,
                    'employee_id' => $employeeId,
                    'customer_count' => $batch->count(),
                    'created_at' => $batchAt,
                    'updated_at' => $batchAt,
                ]);

                foreach ($batch as $target) {
                    $opened = $this->world->chance(0.72);
                    $firstOpen = $opened ? Carbon::parse($batchAt)->addMinutes(mt_rand(20, 600))->min($this->world->now) : null;

                    $assignmentId = DB::table('customer_assignments')->insertGetId([
                        'batch_id' => $batchId,
                        'customer_id' => $target['customer_id'],
                        'ai_customer_record_id' => $target['ai_customer_record_id'],
                        'employee_id' => $employeeId,
                        'assigned_by' => $assignedBy,
                        'opens_count' => $opened ? mt_rand(1, 6) : 0,
                        'first_opened_at' => $firstOpen,
                        'last_opened_at' => $firstOpen?->copy()->addHours(mt_rand(0, 48))->min($this->world->now),
                        'created_at' => $batchAt,
                        'updated_at' => $batchAt,
                    ]);

                    if ($opened && $target['ai_customer_record_id'] && $this->world->chance(0.7)) {
                        $status = $this->world->weighted(self::LEAD_STATUSES);
                        $this->queueFollowUp(
                            ['ai_customer_record_id' => $target['ai_customer_record_id']],
                            (int) $employeeId,
                            $status,
                            $firstOpen->copy(),
                            in_array($status, self::TERMINAL, true) ? null : $this->nextFollowUp($firstOpen, true),
                            $status === 'Eligible for Other Bank' ? $this->bankIdFor($this->world->pick(DemoWorld::OTHER_BANKS)) : null,
                        );
                    }

                    if ($opened && $this->world->chance(0.3)) {
                        DB::table('customer_assignment_remarks')->insert([
                            'customer_assignment_id' => $assignmentId,
                            'employee_id' => $employeeId,
                            'remark' => $this->world->pick(['Spoke to customer, sharing details on WhatsApp.', 'Wrong number on the sheet.', 'Customer interested, meeting planned.', 'Asked to call after salary credit.']),
                            'created_at' => $firstOpen,
                            'updated_at' => $firstOpen,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Callers who found the PAN already belongs to a colleague's
     * customer and asked for it to be released to them.
     */
    private function seedPanRequests(): void
    {
        $requesters = $this->world->employeesWith(Employee::DESIGNATION_CALLER)->shuffle()->take(20)->values();
        $personaCaller = $this->world->personaEmployeeIds['caller'];

        if (! $requesters->contains('id', $personaCaller)) {
            $requesters->push($this->world->employee($personaCaller));
        }

        foreach ($requesters as $index => $requester) {
            $existing = DB::table('customers')->where('employee_id', '!=', $requester->id)->inRandomOrder()->first();
            $monthOffset = $index % 4 === 0 ? 1 : 0;
            $at = $this->world->timeOn($this->randomDay($this->world->monthStart($monthOffset), $this->world->monthEndOrToday($monthOffset)));
            $status = $this->world->weighted(['pending' => 40, 'approved' => 42, 'rejected' => 18]);
            $bankName = $this->world->pick([...DemoWorld::IN_HOUSE_BANKS, ...DemoWorld::OTHER_BANKS]);
            $approver = $this->world->employee($requester->cluster_id);
            $name = fn (?int $id): ?string => $id ? $this->world->employee($id)->emp_name : null;

            $id = DB::table('customer_pan_requests')->insertGetId([
                'customer_id' => $existing->id,
                'pan_number' => $existing->pan_number,
                'requested_by' => $requester->id,
                'requested_by_emp_id' => $requester->emp_id,
                'requested_by_name' => $requester->emp_name,
                'team_leader_id' => $requester->superviser_id,
                'team_leader_name' => $name($requester->superviser_id),
                'manager_id' => $requester->manager_id,
                'manager_name' => $name($requester->manager_id),
                'cluster_manager_id' => $requester->cluster_id,
                'cluster_manager_name' => $name($requester->cluster_id),
                'business_head_id' => $requester->business_head_id,
                'business_head_name' => $name($requester->business_head_id),
                'requested_bank_id' => $this->bankIdFor($bankName),
                'requested_bank_name' => $bankName,
                'requested_loan_type' => $this->world->weighted(['personal_loan' => 70, 'business_loan' => 10, 'home_loan' => 8, 'lap' => 5, 'car_loan' => 4, 'education_loan' => 3]),
                'reason' => $this->world->pick([
                    'Customer approached me directly; earlier file was dropped last month.',
                    'Existing file is inactive for 30+ days, customer wants a fresh application.',
                    'Customer wants a different bank than the earlier application.',
                    'Earlier file was logged by a colleague who has moved teams.',
                ]),
                'status' => $status,
                'approved_by' => $status === 'pending' ? null : $approver?->id,
                'approved_at' => $status === 'pending' ? null : $at->copy()->addHours(mt_rand(2, 26))->min($this->world->now),
                'remarks' => match ($status) {
                    'approved' => 'Approved — earlier file inactive.',
                    'rejected' => 'Rejected — existing file is still active with the bank.',
                    default => null,
                },
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            DB::table('customer_pan_requests')->where('id', $id)->update(['request_no' => 'PR'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
        }
    }

    /**
     * Where a live prospect's next call lands: a few overdue, several due
     * today, most over the next two weeks. Stale prospects from earlier
     * months are left overdue.
     */
    private function nextFollowUp(Carbon $loggedAt, bool $isLive): Carbon
    {
        if (! $isLive) {
            return $loggedAt->copy()->addDays(mt_rand(1, 4))->setTime(mt_rand(10, 18), $this->world->pick([0, 15, 30, 45]));
        }

        $bucket = $this->world->weighted(['overdue' => 15, 'today' => 22, 'soon' => 63]);
        $today = $this->world->today->copy();

        $day = match ($bucket) {
            'overdue' => $today->copy()->subDays(mt_rand(1, 4))->max($loggedAt->copy()->addDay()->startOfDay()),
            'today' => $today,
            default => $today->copy()->addDays(mt_rand(1, 14)),
        };

        if ($day->lessThan($loggedAt->copy()->startOfDay())) {
            $day = $today;
        }

        return $day->setTime(mt_rand(10, 18), $this->world->pick([0, 15, 30, 45]));
    }

    /**
     * @param  array<string, int>  $subject
     */
    private function queueFollowUp(array $subject, ?int $employeeId, string $status, Carbon $at, ?Carbon $next, ?int $bankId): void
    {
        $this->followUps[] = [
            'customer_id' => $subject['customer_id'] ?? null,
            'ai_customer_record_id' => $subject['ai_customer_record_id'] ?? null,
            'lead_id' => $subject['lead_id'] ?? null,
            'employee_id' => $employeeId,
            'follow_up_type' => $this->world->weighted(['Call' => 76, 'WhatsApp' => 20, 'Visit' => 2, 'Email' => 2]),
            'remarks' => $this->world->pick(self::REMARKS[$status]),
            'next_follow_up_date' => $next,
            'status' => $status,
            'bank_id' => $bankId,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /** Oldest first, so each prospect's newest row also has the highest id. */
    private function flushFollowUps(): void
    {
        usort($this->followUps, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $this->world->insert('follow_ups', $this->followUps);
        $this->followUps = [];
    }

    private function bankIdFor(string $name): int
    {
        foreach ($this->world->banks as $bank) {
            if ($bank['name'] === $name) {
                return $bank['id'];
            }
        }

        return array_key_first($this->world->banks);
    }

    private function randomDay(Carbon $from, Carbon $to): Carbon
    {
        $span = max(0, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()));

        return $from->copy()->startOfDay()->addDays(mt_rand(0, $span));
    }
}
