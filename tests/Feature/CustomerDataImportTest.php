<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Filament\Resources\AssignedLeads\Pages\ListAssignedLeads;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\CustomerDataField;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerDataImportService;
use App\Support\SpreadsheetFile;
use DateTimeImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Import Data": the Admin uploads any Excel/CSV, ticks the fields to upload
 * (saved fields matching the file's column names come ticked), can add a new
 * field on the spot that is offered again next time, and the template is
 * built from the ticked fields — no Document Schema has to be made first.
 */
class CustomerDataImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $caller;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $adminEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);
        $this->admin = User::factory()->create(['employee_id' => $adminEmployee->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'emp_name' => 'Asha Rao']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_starter_fields_cover_the_usual_lead_details(): void
    {
        $this->assertSame(
            ['customer_name', 'mobile_number', 'pan_number', 'cibil_score', 'date_of_birth', 'residence_location', 'job_location', 'company_name', 'email', 'salary'],
            CustomerDataField::query()->ordered()->pluck('key')->all(),
        );
    }

    public function test_matching_columns_are_ticked_and_other_columns_are_offered_as_new_fields(): void
    {
        $rows = app(CustomerDataImportService::class)->suggestFields(['Name', 'Contact No.', 'PAN', 'CIBIL', 'DOB', 'Loan Amount']);
        $byLabel = collect($rows)->keyBy('label');

        foreach (['Customer Name' => 'c0', 'Mobile Number' => 'c1', 'PAN Number' => 'c2', 'CIBIL Score' => 'c3', 'Date of Birth' => 'c4'] as $label => $column) {
            $this->assertTrue($byLabel[$label]['include'], "{$label} should be ticked");
            $this->assertFalse($byLabel[$label]['is_new']);
            $this->assertSame($column, $byLabel[$label]['column']);
        }

        // An unknown column is offered as a new field, unticked, with a guessed type.
        $this->assertFalse($byLabel['Loan Amount']['include']);
        $this->assertTrue($byLabel['Loan Amount']['is_new']);
        $this->assertSame('c5', $byLabel['Loan Amount']['column']);
        $this->assertSame('number', $byLabel['Loan Amount']['type']);

        // Saved fields the file does not have stay on the list, unticked and unmapped.
        $this->assertFalse($byLabel['Company Name']['include']);
        $this->assertNull($byLabel['Company Name']['column']);

        // Ticked fields come first, then new columns, then the other saved fields.
        $this->assertSame(['Customer Name', 'Mobile Number', 'PAN Number', 'CIBIL Score', 'Date of Birth', 'Loan Amount'], array_slice(array_column($rows, 'label'), 0, 6));
    }

    public function test_import_builds_the_template_from_the_ticked_fields(): void
    {
        $file = $this->csv([
            ['Name', 'Contact No', 'PAN', 'CIBIL', 'Unused'],
            ['Meena Shah', '+91 98765 43210', 'abcde 1234f', '765', 'x'],
            ['', '', '', '', 'only an unticked column'],
            ['Kiran Das', '9123456780', 'PQRSX9876K', '702', ''],
        ]);

        $result = app(CustomerDataImportService::class)->import($file, 'CIBIL Data_23Nov 1st', [
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => 'c0'],
            ['key' => 'mobile_number', 'include' => true, 'label' => 'Mobile Number', 'column' => 'c1'],
            ['key' => 'pan_number', 'include' => true, 'label' => 'PAN Number', 'column' => 'c2'],
            ['key' => 'cibil_score', 'include' => true, 'label' => 'CIBIL Score', 'column' => 'c3'],
            ['key' => null, 'is_new' => true, 'include' => false, 'label' => 'Unused', 'column' => 'c4', 'type' => 'text'],
        ], uploadedBy: $this->admin);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['assigned']);

        $template = $result['template'];
        $this->assertSame('CIBIL Data_23Nov 1st', $template->name);
        $this->assertTrue($template->is_active);
        $this->assertSame(['customer_name', 'mobile_number', 'pan_number', 'cibil_score'], array_column($template->getFieldDefinitions(), 'key'));

        $meena = AiCustomerRecord::query()->where('schema_id', $template->id)->orderBy('id')->firstOrFail();
        $this->assertSame(
            ['customer_name' => 'Meena Shah', 'mobile_number' => '9876543210', 'pan_number' => 'ABCDE1234F', 'cibil_score' => '765'],
            $meena->data,
        );
        $this->assertSame('review', $meena->status);

        // Stored under the canonical keys, so every screen reading prospect details finds them.
        $this->assertSame('Meena Shah', $meena->prospectValue('customer_name'));
        $this->assertSame('9876543210', $meena->prospectValue('mobile_number'));

        // An unticked column never becomes a saved field.
        $this->assertFalse(CustomerDataField::query()->where('key', 'unused')->exists());
    }

    public function test_a_new_field_is_saved_and_ticked_automatically_on_the_next_upload(): void
    {
        $service = app(CustomerDataImportService::class);

        $service->import($this->csv([['Name', 'Loan Amt'], ['Meena Shah', '500000']]), 'Lot 1', [
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => 'c0'],
            ['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Loan Amount', 'column' => 'c1', 'type' => 'number'],
        ], uploadedBy: $this->admin);

        $loanAmount = CustomerDataField::query()->where('key', 'loan_amount')->firstOrFail();
        $this->assertSame('Loan Amount', $loanAmount->label);
        $this->assertSame('number', $loanAmount->type);
        $this->assertSame($this->admin->id, $loanAmount->created_by);
        $this->assertSame(['Loan Amt'], $loanAmount->aliases);

        $record = AiCustomerRecord::query()->latest('id')->firstOrFail();
        $this->assertSame('500000', $record->data['loan_amount']);

        // Next file: both the field name and the column name it came from are recognised.
        foreach (['Loan Amount', 'Loan Amt'] as $columnName) {
            $suggested = collect($service->suggestFields(['Name', $columnName]))->firstWhere('key', 'loan_amount');

            $this->assertTrue($suggested['include']);
            $this->assertFalse($suggested['is_new']);
            $this->assertSame('c1', $suggested['column']);
        }
    }

    public function test_a_column_matched_by_hand_is_remembered_for_the_saved_field(): void
    {
        $service = app(CustomerDataImportService::class);

        $this->assertFalse(collect($service->suggestFields(['Cust Nm']))->firstWhere('key', 'customer_name')['include']);

        $service->import($this->csv([['Cust Nm'], ['Meena Shah']]), 'Lot 1', [
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => 'c0'],
        ]);

        $this->assertTrue(collect($service->suggestFields(['Cust Nm']))->firstWhere('key', 'customer_name')['include']);
    }

    public function test_a_new_field_named_like_a_saved_one_reuses_the_saved_field(): void
    {
        app(CustomerDataImportService::class)->import($this->csv([['Phone'], ['98765 43210']]), 'Lot 1', [
            ['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Contact Number', 'column' => 'c0', 'type' => 'text'],
        ]);

        $this->assertSame(10, CustomerDataField::query()->count());
        $this->assertSame(['mobile_number' => '9876543210'], AiCustomerRecord::query()->firstOrFail()->data);
    }

    public function test_an_existing_template_name_adds_rows_and_missing_fields_to_that_template(): void
    {
        $template = AiDocumentSchema::create([
            'name' => 'Dropped Cases Nov',
            'is_active' => true,
            'fields' => [['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text']],
        ]);

        $result = app(CustomerDataImportService::class)->import($this->csv([['Name', 'Mobile'], ['Meena Shah', '9876543210']]), 'dropped cases nov', [
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => 'c0'],
            ['key' => 'mobile_number', 'include' => true, 'label' => 'Mobile Number', 'column' => 'c1'],
        ]);

        $this->assertTrue($result['template']->is($template));
        $this->assertSame(1, AiDocumentSchema::query()->count());
        $this->assertSame(['customer_name', 'mobile_number'], array_column($template->refresh()->getFieldDefinitions(), 'key'));
        $this->assertSame(1, $template->records()->count());
    }

    public function test_imported_rows_can_be_assigned_to_one_employee_straight_away(): void
    {
        $result = app(CustomerDataImportService::class)->import(
            $this->csv([['Name'], ['Meena Shah'], ['Kiran Das']]),
            'Lot 1',
            [['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => 'c0']],
            $this->caller->id,
            $this->admin,
        );

        $this->assertSame(2, $result['assigned']);

        $assignments = CustomerAssignment::query()->get();
        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments->every(fn (CustomerAssignment $assignment): bool => $assignment->employee_id === $this->caller->id
            && $assignment->ai_document_schema_id === $result['template']->id
            && $assignment->assigned_by === $this->admin->employee_id));
    }

    public function test_excel_files_skip_a_title_row_and_read_dates_and_numeric_mobiles(): void
    {
        $path = $this->temporaryPath('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['ICICI Login Data – Nov']));
        $writer->addRow(Row::fromValues(['Name', 'Mobile', 'DOB', 'Name']));
        $writer->addRow(new Row([
            Cell::fromValue('Meena Shah'),
            Cell::fromValue(9876543210),
            Cell::fromValue(new DateTimeImmutable('1990-01-15'), (new Style)->setFormat('dd/mm/yyyy')),
            Cell::fromValue('Second name column'),
        ]));
        $writer->close();

        $file = new SpreadsheetFile($path, 'xlsx');

        $this->assertSame(['Name', 'Mobile', 'DOB', 'Name (2)'], $file->columns());
        $this->assertSame([['Meena Shah', '9876543210', '1990-01-15', 'Second name column']], iterator_to_array($file->dataRows(), false));
    }

    public function test_semicolon_separated_csv_files_are_read(): void
    {
        $path = $this->temporaryPath('csv');
        file_put_contents($path, "\u{FEFF}Name;Mobile\nMeena Shah;9876543210\n");

        $file = new SpreadsheetFile($path, 'csv');

        $this->assertSame(['Name', 'Mobile'], $file->columns());
        $this->assertSame([['Meena Shah', '9876543210']], iterator_to_array($file->dataRows(), false));
    }

    public function test_old_xls_files_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpreadsheetFile('/tmp/whatever.xls', 'xls');
    }

    /**
     * @param  list<array<string, mixed>>  $fieldRows
     */
    #[DataProvider('invalidChoices')]
    public function test_invalid_choices_are_refused_without_saving_anything(array $fieldRows, string $message): void
    {
        try {
            app(CustomerDataImportService::class)->import($this->csv([['Name', 'Mobile'], ['Meena Shah', '9876543210']]), 'Lot 1', $fieldRows);

            $this->fail('The import should have been refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(0, AiDocumentSchema::query()->count());
        $this->assertSame(0, AiCustomerRecord::query()->count());
        $this->assertSame(10, CustomerDataField::query()->count());
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function invalidChoices(): array
    {
        return [
            'nothing ticked' => [
                [['key' => 'customer_name', 'include' => false, 'label' => 'Customer Name', 'column' => 'c0']],
                'Tick at least one field',
            ],
            'no column picked' => [
                [['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name', 'column' => null]],
                'Pick the column in your file for "Customer Name"',
            ],
            'new field without a name' => [
                [['key' => null, 'is_new' => true, 'include' => true, 'label' => ' ', 'column' => 'c0']],
                'Give every ticked new field a name',
            ],
            'same field ticked twice' => [
                [
                    ['key' => 'mobile_number', 'include' => true, 'label' => 'Mobile Number', 'column' => 'c1'],
                    ['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Mobile', 'column' => 'c1', 'type' => 'mobile'],
                ],
                'ticked twice',
            ],
            'column not in the file' => [
                [['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Remarks', 'column' => 'c9']],
                'Pick the column in your file for "Remarks"',
            ],
        ];
    }

    public function test_a_file_with_nothing_under_the_ticked_columns_is_refused(): void
    {
        $this->expectExceptionMessage('No rows with data were found under the ticked columns.');

        app(CustomerDataImportService::class)->import($this->csv([['Name', 'Remarks'], ['Meena Shah', '']]), 'Lot 1', [
            ['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Remarks', 'column' => 'c1'],
        ]);
    }

    public function test_the_admin_imports_from_the_assigned_leads_screen(): void
    {
        $upload = UploadedFile::fake()->createWithContent('CIBIL Data_23Nov 1st.csv', "Name,Contact No,Company\nMeena Shah,9876543210,Infosys\n");

        $component = Livewire::test(ListAssignedLeads::class)
            ->assertActionVisible(TestAction::make('importCustomerData')->table())
            ->mountAction(TestAction::make('importCustomerData')->table())
            ->fillForm(['file' => $upload])
            ->assertSchemaStateSet(['template_name' => 'CIBIL Data_23Nov 1st']);

        $fields = collect($component->instance()->mountedActions[0]['data']['fields']);
        $this->assertSame(['Customer Name', 'Mobile Number', 'Company Name'], $fields->where('include', true)->pluck('label')->values()->all());

        $component
            ->fillForm(['employee_id' => $this->caller->id])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Data imported');

        $template = AiDocumentSchema::query()->where('name', 'CIBIL Data_23Nov 1st')->firstOrFail();
        $record = AiCustomerRecord::query()->where('schema_id', $template->id)->firstOrFail();

        $this->assertSame(['customer_name' => 'Meena Shah', 'mobile_number' => '9876543210', 'company_name' => 'Infosys'], $record->data);
        $this->assertSame($this->caller->id, $record->latestAssignment?->employee_id);
    }

    public function test_the_import_action_is_offered_on_customer_data_too(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->assertActionVisible(TestAction::make('importCustomerData')->table());
    }

    public function test_only_admins_see_the_import_action(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));

        Livewire::test(ListAssignedLeads::class)
            ->assertActionHidden(TestAction::make('importCustomerData')->table());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): SpreadsheetFile
    {
        $path = $this->temporaryPath('csv');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        fclose($handle);

        return new SpreadsheetFile($path, 'csv');
    }

    private function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/customer-data-import-'.Str::random(16).'.'.$extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
