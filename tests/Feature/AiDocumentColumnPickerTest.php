<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Filament\Resources\CustomerDataFields\CustomerDataFieldResource;
use App\Filament\Resources\CustomerDataFields\Pages\ManageCustomerDataFields;
use App\Filament\Resources\OcrDocuments\Pages\CreateOcrDocument;
use App\Filament\Resources\OcrDocuments\Pages\EditOcrDocument;
use App\Jobs\ProcessOcrDocument;
use App\Models\AiDocumentSchema;
use App\Models\CustomerDataField;
use App\Models\Employee;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\CustomerDataImportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AI Documents no longer need a hand-made Data Template: the Admin ticks
 * the document's columns from the saved Data Fields (the last document's
 * columns come ticked) and orders them left to right; the template is
 * built from that list. Data Fields replaces Data Templates in the sidebar.
 */
class AiDocumentColumnPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN])->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    public function test_the_first_document_starts_with_name_and_mobile_ticked(): void
    {
        $rows = app(CustomerDataImportService::class)->documentFieldRows();

        $this->assertSame(['customer_name', 'mobile_number'], array_column(array_filter($rows, fn (array $row): bool => $row['include']), 'key'));
        $this->assertSame(['customer_name', 'mobile_number'], array_column(array_slice($rows, 0, 2), 'key'));
        $this->assertCount(CustomerDataField::query()->count(), $rows);
    }

    public function test_the_last_documents_columns_come_ticked_in_its_order(): void
    {
        // An older hand-made template with its own keys and labels.
        $legacy = AiDocumentSchema::create([
            'name' => 'ICICI Login Data 21-Oct',
            'is_active' => true,
            'fields' => [
                ['key' => 'phone_number', 'label' => 'Number', 'type' => 'mobile'],
                ['key' => 'customer_name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'loan_type', 'label' => 'Loan Type', 'type' => 'text'],
            ],
        ]);
        $this->document($legacy);

        $rows = app(CustomerDataImportService::class)->documentFieldRows();
        $ticked = array_values(array_filter($rows, fn (array $row): bool => $row['include']));

        // "Number" is the saved Mobile Number field; "Loan Type" is offered as a new field.
        $this->assertSame(['Mobile Number', 'Customer Name', 'Loan Type'], array_column($ticked, 'label'));
        $this->assertSame([false, false, true], array_column($ticked, 'is_new'));

        // Every saved field is still listed once.
        $this->assertCount(CustomerDataField::query()->count() + 1, $rows);
    }

    public function test_the_template_holds_exactly_the_ticked_columns_in_order(): void
    {
        $template = app(CustomerDataImportService::class)->templateForDocument('Axis Enquiry Nov', [
            ['key' => 'pan_number', 'include' => true, 'label' => 'PAN Number'],
            ['key' => 'salary', 'include' => false, 'label' => 'Salary'],
            ['key' => null, 'is_new' => true, 'include' => true, 'label' => 'Created On', 'type' => 'date'],
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name'],
        ], $this->admin);

        $this->assertSame('Axis Enquiry Nov', $template->name);
        $this->assertSame(['pan_number', 'created_on', 'customer_name'], array_column($template->getFieldDefinitions(), 'key'));
        $this->assertSame('date', CustomerDataField::query()->where('key', 'created_on')->value('type'));
    }

    public function test_a_repeat_name_reuses_the_same_columns_or_gets_numbered(): void
    {
        $service = app(CustomerDataImportService::class);
        $nameAndMobile = [
            ['key' => 'customer_name', 'include' => true, 'label' => 'Customer Name'],
            ['key' => 'mobile_number', 'include' => true, 'label' => 'Mobile Number'],
        ];

        $first = $service->templateForDocument('Lot 1', $nameAndMobile);
        $same = $service->templateForDocument('lot 1', $nameAndMobile);
        $reordered = $service->templateForDocument('Lot 1', array_reverse($nameAndMobile));

        $this->assertTrue($same->is($first));
        $this->assertFalse($reordered->is($first));
        $this->assertSame('Lot 1 (2)', $reordered->name);
        $this->assertSame(['mobile_number', 'customer_name'], array_column($reordered->getFieldDefinitions(), 'key'));
    }

    public function test_a_document_needs_at_least_one_ticked_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CustomerDataImportService::class)->templateForDocument('Lot 1', [
            ['key' => 'customer_name', 'include' => false, 'label' => 'Customer Name'],
        ]);
    }

    public function test_uploading_a_document_builds_its_template_from_the_ticked_columns(): void
    {
        Storage::fake('local');
        Bus::fake();

        $component = Livewire::test(CreateOcrDocument::class)
            ->assertDontSee('Data Template')
            ->assertSee('Columns in this document');

        // Defaults: Customer Name, Mobile Number ticked; tick PAN as a third column.
        $columns = collect($component->get('data.columns'))
            ->map(fn (array $row): array => [...$row, 'include' => $row['include'] || $row['key'] === 'pan_number'])
            ->all();

        $component
            ->fillForm([
                'original_path' => UploadedFile::fake()->create('ICICI Leads 23 Nov.pdf', 20, 'application/pdf'),
                'columns' => $columns,
            ])
            ->assertSchemaStateSet(['title' => 'ICICI Leads 23 Nov'])
            ->assertSee('1. Customer Name')
            ->call('create')
            ->assertHasNoFormErrors();

        $document = OcrDocument::query()->with('schema')->sole();

        $this->assertSame('ICICI Leads 23 Nov.pdf', $document->original_name);
        $this->assertSame('ICICI Leads 23 Nov', $document->title);
        $this->assertSame('ICICI Leads 23 Nov', $document->schema->name);
        $this->assertSame(['customer_name', 'mobile_number', 'pan_number'], array_column($document->schema->getFieldDefinitions(), 'key'));

        Bus::assertDispatched(ProcessOcrDocument::class);

        // Next upload starts from this document's columns.
        $this->assertSame(
            ['customer_name', 'mobile_number', 'pan_number'],
            array_column(array_filter(app(CustomerDataImportService::class)->documentFieldRows(), fn (array $row): bool => $row['include']), 'key'),
        );
    }

    public function test_uploading_with_no_column_ticked_is_refused(): void
    {
        Storage::fake('local');
        Bus::fake();

        $component = Livewire::test(CreateOcrDocument::class);
        $columns = collect($component->get('data.columns'))->map(fn (array $row): array => [...$row, 'include' => false])->all();

        $component
            ->fillForm([
                'original_path' => UploadedFile::fake()->create('leads.pdf', 20, 'application/pdf'),
                'columns' => $columns,
            ])
            ->call('create')
            ->assertHasFormErrors(['columns']);

        $this->assertSame(0, OcrDocument::query()->count());
        $this->assertSame(0, AiDocumentSchema::query()->count());
        Bus::assertNotDispatched(ProcessOcrDocument::class);
    }

    public function test_editing_a_document_shows_its_columns_without_the_picker(): void
    {
        $document = $this->document(AiDocumentSchema::create([
            'name' => 'Lot 1',
            'is_active' => true,
            'fields' => [['key' => 'customer_name', 'label' => 'Customer Name'], ['key' => 'mobile_number', 'label' => 'Mobile Number']],
        ]));

        Livewire::test(EditOcrDocument::class, ['record' => $document->id])
            ->assertSee('1. Customer Name')
            ->assertSee('2. Mobile Number')
            ->assertDontSee('Add new field');
    }

    public function test_data_fields_can_be_added_and_duplicates_are_refused(): void
    {
        Livewire::test(ManageCustomerDataFields::class)
            ->callAction('create', ['label' => 'Loan Amount', 'type' => 'number', 'aliases' => ['Loan Amt']])
            ->assertHasNoFormErrors();

        $field = CustomerDataField::query()->where('key', 'loan_amount')->firstOrFail();
        $this->assertSame('Loan Amount', $field->label);
        $this->assertSame($this->admin->id, $field->created_by);
        $this->assertSame((int) CustomerDataField::query()->max('sort_order'), $field->sort_order);

        // "Contact No" is already one of Mobile Number's names.
        Livewire::test(ManageCustomerDataFields::class)
            ->callAction('create', ['label' => 'Contact No', 'type' => 'mobile'])
            ->assertHasFormErrors(['label']);
    }

    public function test_data_fields_replace_data_templates_in_the_sidebar(): void
    {
        $this->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(CustomerDataFieldResource::getUrl('index'), false)
            ->assertDontSee('Data Templates');
    }

    public function test_customer_data_no_longer_offers_the_template_csv_import(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->assertActionDoesNotExist(TestAction::make('import')->table())
            ->assertActionVisible(TestAction::make('importCustomerData')->table());
    }

    private function document(AiDocumentSchema $schema): OcrDocument
    {
        return OcrDocument::create([
            'schema_id' => $schema->id,
            'original_path' => 'ocr-documents/leads.pdf',
            'original_name' => 'leads.pdf',
            'title' => $schema->name,
            'status' => 'completed',
            'uploaded_by' => $this->admin->id,
        ]);
    }
}
