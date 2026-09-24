<?php

namespace Database\Seeders\Demo;

use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Bank;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentRemark;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use Database\Seeders\Demo\Concerns\DemoSeedState;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The top of the funnel: open leads with their follow-up log, the
 * AI-imported customer data (a schema, OCR'd documents and the records
 * extracted from them), batches of that data assigned to callers, and a
 * few duplicate-PAN requests.
 *
 * Leads are created and updated through the Lead model, so its hooks write
 * the follow_ups rows (one per interaction) exactly as the panel does.
 * OCR documents are record rows only — each points at a tiny placeholder
 * PDF on the demo disk and nothing is sent through OCR.
 */
class DemoProspectingSeeder extends Seeder
{
    use SeedsDemoTimeline;

    /** @var list<string> */
    protected const LEAD_NAMES = [
        'Aditi Sood', 'Bharat Kohli', 'Chitra Menon', 'Dev Anand Rao', 'Ekta Sinha', 'Firoz Khan', 'Geeta Paul',
        'Hemant Dutta', 'Indu Bhalla', 'Jatin Kaul', 'Komal Dhawan', 'Lalit Bajaj', 'Mansi Oberoi', 'Naveen Suri',
        'Owais Siddiqui', 'Preeti Chawla', 'Rakesh Mathur', 'Sakshi Ahuja', 'Tarun Goel', 'Urmila Das',
        'Varun Sahni', 'Waseem Ali', 'Yamini Kohli', 'Zubin Mistry',
    ];

    /**
     * @var array<string, array{0: string, 1: bool}> status => [remark, has next follow-up]
     */
    protected const LEAD_STATUSES = [
        'Pending' => ['First call done, customer asked to call back.', true],
        'Interested' => ['Interested, collecting salary slips and bank statement.', true],
        'Busy' => ['Customer busy in a meeting, call again.', true],
        'No Response' => ['Phone not answered twice.', true],
        'Not Interested' => ['Already has a loan offer from own bank.', false],
        'Not Eligible' => ['Salary below the minimum for any partner product.', false],
        'Eligible for Other Bank' => ['Not eligible for BFL, policy fits another lender.', true],
    ];

    /**
     * @var array<int, User>
     */
    protected array $loginsByEmployee = [];

    /**
     * @var Collection<int, string>
     */
    protected Collection $cityNames;

    protected int $leadNameCursor = 0;

    public function run(): void
    {
        $this->loginsByEmployee = User::query()->whereNotNull('employee_id')->get()->keyBy('employee_id')->all();
        $this->cityNames = City::query()->pluck('city');

        $callers = Employee::query()
            ->where('designation', Employee::DESIGNATION_CALLER)
            ->where('exit_status', '!=', 'yes')
            ->orderBy('id')
            ->get();

        foreach ($callers as $caller) {
            $this->seedLeads($caller);
        }

        $records = $this->seedAiCustomerData();
        $this->seedAssignments($callers, $records);
        $this->seedPanRequests($callers);
    }

    protected function seedLeads(Employee $caller): void
    {
        $callerUser = $this->loginsByEmployee[$caller->id];
        $today = $this->realNow()->copy()->startOfDay();
        $earliest = Carbon::parse($caller->doj)->max($today->copy()->subDays(30));

        foreach (range(1, fake()->numberBetween(3, 5)) as $ignored) {
            $createdOn = $earliest->copy()->addDays(fake()->numberBetween(0, (int) $earliest->diffInDays($today)));
            $status = fake()->randomElement(array_keys(self::LEAD_STATUSES));
            $name = $this->nextLeadName();
            $bankId = $status === 'Eligible for Other Bank'
                ? Bank::query()->whereNotIn('bank_name', ['BFL Prime', 'BFL Growth', 'BFL SOL', 'BFL RSL'])->inRandomOrder()->value('id')
                : null;

            $lead = $this->replay($this->momentOn($createdOn, fake()->numberBetween(10, 17), fake()->numberBetween(0, 59)), $callerUser, fn (): Lead => Lead::query()->create([
                'employee_id' => $caller->id,
                'customer_name' => $name,
                'mobile_no' => DemoSeedState::nextMobile(),
                'email' => str_replace(' ', '.', strtolower($name)).'@'.DemoSeedState::EMAIL_DOMAIN,
                'pan_number' => fake()->boolean(60) ? DemoSeedState::nextPan() : null,
                'current_location' => $this->cityNames->random(),
                'job_location' => $this->cityNames->random(),
                'residence_location' => $this->cityNames->random(),
                'salary' => fake()->randomElement([28000, 35000, 42000, 55000, 68000, 90000]),
                'follow_up_type' => fake()->randomElement(['Call', 'WhatsApp']),
                'status' => 'Pending',
                'next_follow_up_date' => $createdOn->copy()->addDays(fake()->numberBetween(1, 3))->setTime(11, 0),
                'remarks' => 'New enquiry from tele-calling list.',
            ]));

            // A second interaction revises the status and the next date;
            // the Lead model logs it as a new follow_ups row.
            if ($status === 'Pending' && fake()->boolean(50)) {
                continue;
            }

            [$remark, $hasNextDate] = self::LEAD_STATUSES[$status];
            $contactedOn = $createdOn->copy()->addDays(fake()->numberBetween(1, 4))->min($today);

            $this->replay($this->momentOn($contactedOn, fake()->numberBetween(10, 17), fake()->numberBetween(0, 59)), $callerUser, fn () => $lead->update([
                'follow_up_type' => fake()->randomElement(['Call', 'WhatsApp', 'Email']),
                'status' => $status,
                'bank_id' => $bankId,
                'remarks' => $remark,
                'next_follow_up_date' => $hasNextDate
                    ? $today->copy()->addDays(fake()->numberBetween(0, 8))->setTime(fake()->randomElement([10, 11, 12, 15, 16, 17]), fake()->randomElement([0, 30]))
                    : null,
            ]));
        }
    }

    /**
     * @return Collection<int, AiCustomerRecord>
     */
    protected function seedAiCustomerData(): Collection
    {
        $admin = User::role('Admin')->firstOrFail();
        $mis = User::role('MIS')->firstOrFail();
        $importedOn = $this->realNow()->copy()->subDays(12)->startOfDay();

        $schema = $this->replay($this->momentOn($this->realNow()->copy()->subDays(40), 11), $admin, fn (): AiDocumentSchema => AiDocumentSchema::query()->create([
            'name' => 'Corporate Salary Data',
            'description' => 'Employee lists shared by corporate HR partners for pre-approved offers.',
            'is_active' => true,
            'created_by' => $admin->id,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'aliases' => ['Name', 'Employee Name'], 'type' => 'string', 'required' => true],
                ['key' => 'mobile_number', 'label' => 'Mobile Number', 'aliases' => ['Mobile', 'Phone'], 'type' => 'string', 'required' => true],
                ['key' => 'pan_number', 'label' => 'PAN', 'aliases' => ['PAN No'], 'type' => 'string', 'required' => false],
                ['key' => 'company_name', 'label' => 'Company', 'aliases' => ['Employer'], 'type' => 'string', 'required' => false],
                ['key' => 'net_salary', 'label' => 'Net Salary', 'aliases' => ['Salary', 'Take Home'], 'type' => 'number', 'required' => false],
                ['key' => 'city', 'label' => 'City', 'aliases' => ['Location'], 'type' => 'string', 'required' => false],
                ['key' => 'product_type', 'label' => 'Product', 'aliases' => ['Loan Type'], 'type' => 'string', 'required' => false],
            ],
        ]));

        $this->replay($this->momentOn($this->realNow()->copy()->subDays(40), 11, 30), $admin, fn (): AiDocumentSchema => AiDocumentSchema::query()->create([
            'name' => 'Bank Statement Summary',
            'description' => 'Average balance and salary credits read from uploaded bank statements.',
            'is_active' => false,
            'created_by' => $admin->id,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Account Holder', 'aliases' => ['Name'], 'type' => 'string', 'required' => true],
                ['key' => 'average_balance', 'label' => 'Average Balance', 'aliases' => ['AMB'], 'type' => 'number', 'required' => false],
                ['key' => 'salary_credits', 'label' => 'Salary Credits (6m)', 'aliases' => ['Salary Credits'], 'type' => 'number', 'required' => false],
            ],
        ]));

        $documents = collect(['Northline Logistics — HR list (Aug)', 'Sunhaven Healthcare — HR list', 'Auralink Technologies — HR list'])
            ->map(fn (string $title, int $index): OcrDocument => $this->ocrDocument($schema, $mis, $title, $importedOn->copy()->addDays($index)));

        $records = collect();

        foreach ($documents as $document) {
            foreach (range(1, 6) as $ignored) {
                $name = $this->nextLeadName();
                $status = fake()->randomElement(['review', 'review', 'approved', 'approved', 'approved', 'rejected']);

                $record = $this->replay($this->momentOn(Carbon::parse($document->processed_at), 13), $mis, fn (): AiCustomerRecord => AiCustomerRecord::query()->create([
                    'schema_id' => $schema->id,
                    'ocr_document_id' => $document->id,
                    'data' => [
                        'customer_name' => $name,
                        'mobile_number' => DemoSeedState::nextMobile(),
                        'pan_number' => DemoSeedState::nextPan(),
                        'company_name' => str($document->title)->before(' —')->toString(),
                        'net_salary' => fake()->randomElement([45000, 52000, 61000, 74000, 88000, 105000]),
                        'city' => $this->cityNames->random(),
                        'product_type' => 'Personal Loan',
                    ],
                    'status' => $status,
                    'confidence_score' => fake()->randomFloat(4, 0.82, 0.99),
                    'reviewed_by' => $status === 'review' ? null : $mis->id,
                    'reviewed_at' => $status === 'review' ? null : now(),
                    'rejection_reason' => $status === 'rejected' ? 'Mobile number unreadable in the source document.' : null,
                ]));

                $records->push($record);
            }
        }

        return $records;
    }

    protected function ocrDocument(AiDocumentSchema $schema, User $uploader, string $title, Carbon $uploadedOn): OcrDocument
    {
        $path = 'ocr-documents/demo-'.str($title)->slug()->toString().'.pdf';
        Storage::disk('local')->put($path, $this->placeholderPdf($title));

        return $this->replay($this->momentOn($uploadedOn, 11), $uploader, fn (): OcrDocument => OcrDocument::query()->create([
            'uploaded_by' => $uploader->id,
            'title' => $title,
            'document_type' => 'hr_list',
            'schema_id' => $schema->id,
            'original_path' => $path,
            'original_name' => str($title)->slug()->toString().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => Storage::disk('local')->size($path),
            'page_count' => 1,
            'status' => 'completed',
            'ocr_text' => "{$title}\nDemo placeholder — no real document was processed.",
            'extracted_data' => ['rows' => 6, 'source' => 'demo placeholder'],
            'confidence_score' => fake()->randomFloat(4, 0.88, 0.97),
            'is_verified' => true,
            'approved_by' => $uploader->id,
            'approved_at' => now(),
            'processed_at' => now(),
        ]));
    }

    /**
     * Hands approved AI records to callers in batches, the way the
     * "Assign to User" bulk action does, then has some callers open them,
     * leave a remark and log a follow-up.
     *
     * @param  Collection<int, Employee>  $callers
     * @param  Collection<int, AiCustomerRecord>  $records
     */
    protected function seedAssignments(Collection $callers, Collection $records): void
    {
        $assignable = $records->where('status', 'approved')->values();
        $recipients = $callers->filter(fn (Employee $caller): bool => $caller->superviser_id !== null || $caller->manager_id !== null)->take(6)->values();
        $assignedOn = $this->realNow()->copy()->subDays(8)->startOfDay();

        foreach ($assignable->chunk(2)->values() as $index => $chunk) {
            $caller = $recipients[$index % $recipients->count()];
            $assigner = $this->assignerFor($caller);

            $this->replay($this->momentOn($assignedOn, 10, $index * 5), $this->loginsByEmployee[$assigner->id], fn () => app(CustomerAssignmentService::class)->assign(
                $chunk->pluck('id'),
                $caller->id,
                CustomerAssignmentService::TARGET_AI_RECORD,
                $assigner->id,
            ));
        }

        // Unconverted, not-yet-eligible LMS files re-assigned for a second attempt.
        $stalled = Customer::query()->where('journey_status', 'not_started')->where('eligibility_status', 'consent_pending')->limit(4)->pluck('id');

        if ($stalled->isNotEmpty()) {
            $caller = $recipients->first();
            $assigner = $this->assignerFor($caller);

            $this->replay($this->momentOn($assignedOn, 12), $this->loginsByEmployee[$assigner->id], fn () => app(CustomerAssignmentService::class)->assign(
                $stalled,
                $caller->id,
                CustomerAssignmentService::TARGET_CUSTOMER,
                $assigner->id,
            ));
        }

        $today = $this->realNow()->copy()->startOfDay();

        CustomerAssignment::query()->with('employee')->orderBy('id')->get()->each(function (CustomerAssignment $assignment, int $index) use ($assignedOn, $today): void {
            if ($index % 3 === 2) {
                return; // still unopened
            }

            $callerUser = $this->loginsByEmployee[$assignment->employee_id];
            $openedOn = $assignedOn->copy()->addDays(fake()->numberBetween(0, 3));

            $this->replay($this->momentOn($openedOn, 11, fake()->numberBetween(0, 59)), $callerUser, function () use ($assignment, $today, $index): void {
                $assignment->recordOpen();

                CustomerAssignmentRemark::query()->create([
                    'customer_assignment_id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'remark' => $index % 2 === 0 ? 'Spoke to the customer, wants an offer on WhatsApp.' : 'Customer asked to call after salary credit.',
                ]);

                FollowUp::query()->create([
                    'customer_id' => $assignment->customer_id,
                    'ai_customer_record_id' => $assignment->ai_customer_record_id,
                    'employee_id' => $assignment->employee_id,
                    'follow_up_type' => 'Call',
                    'status' => $index % 2 === 0 ? 'Interested' : 'Pending',
                    'remarks' => 'Assigned lead contacted.',
                    'next_follow_up_date' => $today->copy()->addDays(fake()->numberBetween(1, 6))->setTime(12, 0),
                ]);
            });
        });
    }

    /**
     * Duplicate-PAN requests: a caller found the customer already on
     * another employee's book and asked the Admin for approval to log a
     * file with a different lender (CustomerForm's request action).
     *
     * @param  Collection<int, Employee>  $callers
     */
    protected function seedPanRequests(Collection $callers): void
    {
        $existing = Customer::query()->whereIn('journey_status', ['not_approved', 'dropped'])->orderBy('id')->limit(3)->get();
        $statuses = ['pending', 'approved', 'rejected'];

        foreach ($existing->values() as $index => $customer) {
            $requester = $callers->first(fn (Employee $caller): bool => $caller->id !== $customer->employee_id && $caller->superviser_id !== null);
            $bank = Bank::query()->where('bank_name', '!=', $customer->bank_eligible_for)->orderBy('id')->skip($index + 4)->first();
            $requestedOn = $this->realNow()->copy()->subDays(6 - $index * 2)->startOfDay();
            $status = $statuses[$index];

            $this->replay($this->momentOn($requestedOn, 14), $this->loginsByEmployee[$requester->id], fn () => CustomerPanRequest::query()->create([
                'customer_id' => $customer->id,
                'pan_number' => $customer->pan_number,
                'requested_by' => $requester->id,
                'requested_by_emp_id' => $requester->emp_id,
                'requested_by_name' => $requester->emp_name,
                'team_leader_id' => $requester->superviser_id,
                'team_leader_name' => $requester->superviser?->emp_name,
                'manager_id' => $requester->manager_id,
                'manager_name' => $requester->manager?->emp_name,
                'cluster_manager_id' => $requester->cluster_id,
                'cluster_manager_name' => $requester->cluster?->emp_name,
                'business_head_id' => $requester->business_head_id,
                'business_head_name' => $requester->businessHead?->emp_name,
                'requested_bank_id' => $bank->id,
                'requested_bank_name' => $bank->bank_name,
                'requested_loan_type' => 'personal_loan',
                'reason' => 'Customer was declined by the previous lender; '.$bank->bank_name.' policy fits the profile.',
                'status' => $status,
                'approved_at' => $status === 'pending' ? null : now()->addHours(3),
                'remarks' => match ($status) {
                    'approved' => 'Approved — log the file with the new lender.',
                    'rejected' => 'Rejected — the earlier file is still under review with the lender.',
                    default => null,
                },
            ]));
        }
    }

    /**
     * The seat that hands work to a caller: their Manager, else the
     * nearest senior seat.
     */
    protected function assignerFor(Employee $caller): Employee
    {
        $bossId = $caller->manager_id ?? $caller->cluster_id ?? $caller->business_head_id ?? $caller->superviser_id;

        return Employee::query()->findOrFail($bossId);
    }

    protected function nextLeadName(): string
    {
        $index = $this->leadNameCursor++;
        $base = self::LEAD_NAMES[$index % count(self::LEAD_NAMES)];
        $round = intdiv($index, count(self::LEAD_NAMES));

        return $round === 0 ? $base : $base.' '.['', 'Jr', 'II', 'III', 'IV', 'V', 'VI', 'VII'][$round % 8];
    }

    /**
     * A minimal valid one-page PDF, so the document viewer has a file to
     * open. Clearly marked as a placeholder.
     */
    protected function placeholderPdf(string $title): string
    {
        $text = str_replace(['(', ')', '\\'], '', 'DEMO PLACEHOLDER - '.$title);
        $stream = "BT /F1 14 Tf 72 720 Td ({$text}) Tj ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
