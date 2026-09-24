<?php

namespace Database\Seeders\Demo;

use App\Models\Bank;
use App\Models\DashboardGreetingSetting;
use App\Models\LoginPageSetting;
use App\Models\PerformanceMetricRatio;
use Database\Seeders\CitySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lookup data every demo screen reads before it shows a single record:
 * roles, cities, lenders, the login page / dashboard greeting settings
 * (both ::current() calls firstOrFail, so the login page and dashboard
 * crash without them) and the performance ratio definitions.
 */
class DemoReferenceDataSeeder extends Seeder
{
    /**
     * Lender master for leads and duplicate-PAN requests. Names match the
     * options the customer form offers, so the two never disagree.
     *
     * @var array<int, array{bank_name: string, loan_type: string, payment_from: string, payout: float}>
     */
    public const BANKS = [
        ['bank_name' => 'BFL Prime', 'loan_type' => 'Personal Loan', 'payment_from' => 'BFL', 'payout' => 2.25],
        ['bank_name' => 'BFL Growth', 'loan_type' => 'Personal Loan', 'payment_from' => 'BFL', 'payout' => 2.00],
        ['bank_name' => 'BFL SOL', 'loan_type' => 'Business Loan', 'payment_from' => 'BFL', 'payout' => 1.75],
        ['bank_name' => 'BFL RSL', 'loan_type' => 'Personal Loan', 'payment_from' => 'BFL', 'payout' => 1.75],
        ['bank_name' => 'ABFL', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 2.50],
        ['bank_name' => 'Incred', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 2.75],
        ['bank_name' => 'Tata Capital', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 2.00],
        ['bank_name' => 'Poonawala', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 2.50],
        ['bank_name' => 'Fibe', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 3.00],
        ['bank_name' => 'Axis Bank', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 1.50],
        ['bank_name' => 'HDFC Bank', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 1.25],
        ['bank_name' => 'IDFC First Bank', 'loan_type' => 'Personal Loan', 'payment_from' => 'Channel', 'payout' => 1.75],
    ];

    public function run(): void
    {
        $this->call([RolesSeeder::class, CitySeeder::class]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::BANKS as $bank) {
            Bank::query()->updateOrCreate(['bank_name' => $bank['bank_name']], $bank + ['is_active' => true]);
        }

        $this->ensureSettings();
    }

    /**
     * The migrations insert these rows; they are re-created here only if a
     * migration's seed row is ever missing, so the login page and dashboard
     * can never be left without them.
     */
    protected function ensureSettings(): void
    {
        if (! LoginPageSetting::query()->exists()) {
            LoginPageSetting::query()->create([
                'left_heading' => 'One Platform. One Team. One Goal.',
                'left_tagline' => 'LEAD • MANAGE • SUCCEED',
                'right_tagline' => 'Simplifying Loan, Amplifying Trust.',
                'welcome_heading' => 'Welcome to the Demo!',
                'welcome_subheading' => 'Sign in to explore Fynn-ON LMS with sample data',
                'footer_text' => '© '.now()->year.' Markedge Technologies. Demo environment — all data is fictitious.',
            ]);
        }

        if (! DashboardGreetingSetting::query()->exists()) {
            DashboardGreetingSetting::query()->create([
                'tagline' => "Let's make every move count!",
                'icon' => 'heroicon-o-rocket-launch',
            ]);
        }

        $ratios = [
            ['name' => 'Login → Approval Ratio', 'numerator_key' => 'approval_count', 'denominator_key' => 'login_count'],
            ['name' => 'Approval → Disbursal Ratio', 'numerator_key' => 'disbursal_count', 'denominator_key' => 'approval_count'],
            ['name' => 'Drop Ratio', 'numerator_key' => 'dropped_count', 'denominator_key' => 'login_count'],
        ];

        foreach ($ratios as $index => $ratio) {
            PerformanceMetricRatio::query()->firstOrCreate(
                ['numerator_key' => $ratio['numerator_key'], 'denominator_key' => $ratio['denominator_key']],
                $ratio + [
                    'format' => PerformanceMetricRatio::FORMAT_PERCENTAGE,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
