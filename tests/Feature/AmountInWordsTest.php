<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\CreateOtherBankIncentiveSlab;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\OtherBankIncentiveSlab;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_macro_shows_the_amount_in_words_only(): void
    {
        Livewire::test(AmountInWordsFormFixture::class)
            ->fillForm(['plain' => '1250000'])
            ->assertSee('Twelve Lakh Fifty Thousand')
            ->assertDontSee('₹12,50,000');
    }

    public function test_macro_parses_a_comma_formatted_amount(): void
    {
        Livewire::test(AmountInWordsFormFixture::class)
            ->fillForm(['plain' => '1,00,00,000'])
            ->assertSee('One Crore');
    }

    public function test_macro_shows_nothing_for_a_blank_amount(): void
    {
        Livewire::test(AmountInWordsFormFixture::class)
            ->fillForm(['plain' => ''])
            ->assertDontSee('Zero');
    }

    public function test_macro_keeps_an_existing_helper_text(): void
    {
        Livewire::test(AmountInWordsFormFixture::class)
            ->fillForm(['with_helper' => '50000'])
            ->assertSee('Enter the gross figure.')
            ->assertSee('Fifty Thousand');
    }

    public function test_macro_makes_a_field_live_on_blur_without_downgrading_an_existing_live(): void
    {
        $onBlur = TextInput::make('a')->amountInWords();
        $alreadyLive = TextInput::make('b')->live()->amountInWords();

        $this->assertTrue($onBlur->isLiveOnBlur());
        $this->assertFalse($alreadyLive->isLiveOnBlur());
    }

    public function test_customer_form_shows_words_for_salary_and_approved_amount(): void
    {
        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
            'eligibility_status' => 'eligible',
            'bank_eligible_for' => 'ABFL',
            'journey_status' => 'underwriting',
            'underwriting_status' => 'approved',
            'salary' => 85000,
            'approved_loan_amount' => 1250000,
        ]);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertSee('Eighty Five Thousand')
            ->assertSee('Twelve Lakh Fifty Thousand')
            ->fillForm(['approved_loan_amount' => '15,00,000'])
            ->assertSee('Fifteen Lakh');
    }

    public function test_slab_payout_shows_words_only_for_a_fixed_payout(): void
    {
        Livewire::test(CreateOtherBankIncentiveSlab::class)
            ->fillForm([
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_FIXED,
                'payout_value' => '250000',
            ])
            ->assertSee('Two Lakh Fifty Thousand')
            ->fillForm([
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_PERCENTAGE,
                'payout_value' => '15',
            ])
            ->assertDontSee('Fifteen');
    }
}

class AmountInWordsFormFixture extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('plain')->amountInWords(),
                TextInput::make('with_helper')
                    ->helperText('Enter the gross figure.')
                    ->amountInWords(),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
