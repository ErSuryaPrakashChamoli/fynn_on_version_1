<?php

namespace Tests\Feature;

use App\Filament\Resources\OtherBankIncentiveSlabs\Pages\CreateOtherBankIncentiveSlab;
use App\Filament\Resources\OtherBankSupportTargets\Pages\CreateOtherBankSupportTarget;
use App\Models\OtherBankIncentiveSlab;
use App\Models\OtherBankSupportTarget;
use App\Models\User;
use App\Services\OtherBankSupportService;
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

/**
 * TextInput::indianAmount() — the one way amounts are taken across the
 * panel: Indian grouping in the box (paise kept), words underneath, a "₹"
 * prefix, plain digits saved, and bounds checked on the amount.
 */
class IndianAmountFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => OtherBankSupportService::ROLE]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_the_helpers_group_the_indian_way_and_keep_paise_and_sign(): void
    {
        $this->assertSame('12,50,000', indianNumberFormat('1250000'));
        $this->assertSame('12,50,000.75', indianNumberFormat('1250000.75'));
        $this->assertSame('12,50,000.50', indianNumberFormat('12,50,000.5'));
        $this->assertSame('-45,000', indianNumberFormat(-45000));
        $this->assertSame('1,00,00,000', indianNumberFormat('10000000.00'));

        $this->assertSame('Twelve Lakh Fifty Thousand', indianAmountInWordsWithPaise('1250000'));
        $this->assertSame('Twelve Lakh Fifty Thousand and Seventy Five Paise', indianAmountInWordsWithPaise('12,50,000.75'));
        $this->assertSame('Fifty Paise', indianAmountInWordsWithPaise('0.50'));
    }

    public function test_the_field_shows_grouping_and_words_and_saves_plain_digits(): void
    {
        Livewire::test(IndianAmountFormFixture::class)
            ->fillForm(['amount' => '1250000.5'])
            ->assertSchemaStateSet(['amount' => '12,50,000.50'])
            ->assertSee('Twelve Lakh Fifty Thousand and Fifty Paise')
            ->assertSee('₹')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved.amount', '1250000.50');
    }

    public function test_an_existing_value_opens_already_grouped(): void
    {
        Livewire::test(IndianAmountFormFixture::class, ['initial' => '2500000.00'])
            ->assertSchemaStateSet(['amount' => '25,00,000']);
    }

    public function test_bounds_are_checked_on_the_amount_not_the_text_length(): void
    {
        Livewire::test(IndianAmountFormFixture::class)
            ->fillForm(['amount' => '0'])
            ->call('save')
            ->assertHasErrors(['data.amount']);

        // "99,99,999" is nine characters; a length check would wrongly pass or fail it.
        Livewire::test(IndianAmountFormFixture::class)
            ->fillForm(['amount' => '99,99,999'])
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(IndianAmountFormFixture::class)
            ->fillForm(['amount' => 'twelve'])
            ->call('save')
            ->assertHasErrors(['data.amount']);
    }

    public function test_a_real_form_saves_the_grouped_amount_as_a_number(): void
    {
        $supportUser = User::factory()->create();
        $supportUser->assignRole(OtherBankSupportService::ROLE);

        Livewire::test(CreateOtherBankSupportTarget::class)
            ->fillForm([
                'user_id' => $supportUser->id,
                'month' => now()->startOfMonth()->toDateString(),
                'target_amount' => '15,00,000',
            ])
            ->assertSee('Fifteen Lakh')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1500000.0, (float) OtherBankSupportTarget::query()->sole()->target_amount);
    }

    public function test_a_percentage_payout_keeps_its_ceiling_and_has_no_rupee_words(): void
    {
        Livewire::test(CreateOtherBankIncentiveSlab::class)
            ->fillForm([
                'effective_month' => now()->startOfMonth()->toDateString(),
                'min_achievement' => '10,00,000',
                'payout_type' => OtherBankIncentiveSlab::PAYOUT_PERCENTAGE,
                'payout_value' => '150',
            ])
            ->call('create')
            ->assertHasFormErrors(['payout_value']);
    }

    public function test_every_known_amount_field_uses_the_macro(): void
    {
        $files = [
            'app/Filament/Pages/MyDailyCommitment.php' => 2,
            'app/Filament/Pages/DailyCommitmentDetail.php' => 3,
            'app/Filament/Resources/MonthlyCommitmentTargets/Schemas/MonthlyCommitmentTargetForm.php' => 1,
            'app/Filament/Resources/OtherBankSupportTargets/Schemas/OtherBankSupportTargetForm.php' => 1,
            'app/Filament/Resources/OtherBankIncentiveSlabs/Schemas/OtherBankIncentiveSlabForm.php' => 2,
            'app/Filament/Resources/CustomerSettlements/RelationManagers/TransactionsRelationManager.php' => 1,
            'app/Filament/Resources/CustomerEditRequests/Schemas/CustomerEditRequestForm.php' => 1,
            'app/Filament/Resources/AccountVerifications/Schemas/AccountVerificationForm.php' => 25,
            'app/Filament/Resources/CustomerSettlements/Schemas/CustomerSettlementForm.php' => 44,
        ];

        foreach ($files as $file => $expected) {
            $this->assertSame($expected, substr_count((string) file_get_contents(base_path($file)), '->indianAmount('), $file);
        }
    }
}

class IndianAmountFormFixture extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public function mount(?string $initial = null): void
    {
        $this->form->fill(['amount' => $initial]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')->indianAmount(min: 1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
