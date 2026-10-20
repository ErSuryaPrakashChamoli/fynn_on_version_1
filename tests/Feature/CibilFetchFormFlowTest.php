<?php

namespace Tests\Feature;

use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Pages\CreateCibilReport;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\CibilSetting;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\FakeCibilClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The fetch form as it is used in the browser: the "Send request" button,
 * its confirmation pop-up, and what the user sees when something is wrong.
 * The Admin here has no employee record, as on the live system.
 */
class CibilFetchFormFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);
        $this->app->instance(CibilClient::class, new FakeCibilClient);
        CibilSetting::current()->update(['requests_enabled' => true]);

        $this->admin = User::factory()->create(['employee_id' => null]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);

        $owner = Employee::factory()->create();
        $this->customer = Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'customer_name' => 'Arohi Mehta',
            'pan_number' => 'AROHI1234M',
            'mobile_no' => '9811122127',
        ]);
    }

    private static function sendButton(): TestAction
    {
        return TestAction::make('create')->schemaComponent('form-actions', schema: 'content');
    }

    /**
     * @return array<string, mixed>
     */
    private function consent(): array
    {
        return ['request_type' => 'full_report', 'gender' => 'female', 'consent_method' => 'digital', 'consent' => true];
    }

    public function test_send_request_asks_for_confirmation_then_fetches_and_opens_the_report(): void
    {
        $page = Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'cibil', 'fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $this->customer->id])
            ->fillForm($this->consent())
            ->mountAction(self::sendButton())
            ->assertActionMounted(self::sendButton())
            ->assertMountedActionModalSee('Send this request to CIBIL?')
            ->assertMountedActionModalSee('ARXXXXXX4M')
            ->assertMountedActionModalDontSee('AROHI1234M')
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('CIBIL report fetched');

        $report = CibilReport::query()->sole();
        $page->assertRedirect(CibilReportResource::getUrl('view', ['record' => $report]));
        $this->assertSame($this->customer->id, $report->customer_id);
    }

    public function test_a_missing_customer_is_shown_on_the_form_and_no_pop_up_hides_it(): void
    {
        Livewire::test(CreateCibilReport::class)
            ->fillForm([
                'bureau' => 'cibil',
                'fetch_for' => 'customer',
                'customer_name' => 'Arohi Mehta',
                'pan_number' => 'AROHI1234M',
                'mobile_no' => '9811122127',
                ...$this->consent(),
            ])
            ->callAction(self::sendButton())
            ->assertHasFormErrors(['customer_id' => 'required'])
            ->assertActionNotMounted(self::sendButton());

        $this->assertSame(0, CibilReport::query()->count());
    }

    public function test_a_refused_request_closes_the_pop_up_and_says_why(): void
    {
        CibilSetting::current()->update(['requests_enabled' => false]);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'cibil', 'fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $this->customer->id])
            ->fillForm($this->consent())
            ->callAction(self::sendButton())
            ->assertNotified('CIBIL request not sent')
            ->assertActionNotMounted(self::sendButton());

        $this->assertSame(0, CibilReport::query()->count());
    }

    public function test_any_open_lead_can_be_picked_and_no_decision_is_asked_outside_bureau_check(): void
    {
        $lead = Lead::create([
            'employee_id' => null,
            'customer_name' => 'Interested Lead',
            'mobile_no' => '9000022222',
            'pan_number' => 'INTER1234L',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Created from the Admin login',
        ]);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['bureau' => 'experian', 'fetch_for' => 'lead'])
            ->fillForm(['lead_key' => 'lead:'.$lead->id])
            ->assertSchemaStateSet(['customer_name' => 'Interested Lead', 'pan_number' => 'INTER1234L', 'mobile_no' => '9000022222'])
            ->fillForm(['request_type' => 'score', 'consent_method' => 'digital', 'consent' => true])
            ->callAction(self::sendButton())
            ->assertHasNoFormErrors()
            ->assertNotified('Experian report fetched');

        $report = CibilReport::query()->sole();
        $this->assertSame($lead->id, $report->lead_id);
        $this->assertNull($report->bureau_decision);
    }
}
