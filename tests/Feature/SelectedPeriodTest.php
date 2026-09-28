<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SelectedMonth;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The topbar period selector: a calendar month (the default), a financial
 * year (April to March) or all time, carried by the `selected_month` and
 * `selected_period` cookies and read through App\Support\SelectedMonth.
 */
class SelectedPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-27 10:00:00'));
    }

    public function test_the_default_is_the_selected_calendar_month(): void
    {
        $this->assertSame('month', SelectedMonth::mode());
        $this->assertSame('2026-09-01', SelectedMonth::current()->toDateString());
        $this->assertSame('Sep 2026', SelectedMonth::label());
        $this->assertTrue(SelectedMonth::isCurrentCalendarMonth());
        $this->assertSame(27, SelectedMonth::elapsedDays());
        $this->assertSame(4, SelectedMonth::remainingDays());
    }

    public function test_a_financial_year_runs_from_april_to_march(): void
    {
        $this->setCookies(['selected_period' => 'fy:2026']);

        $this->assertSame('fy', SelectedMonth::mode());
        $this->assertSame(2026, SelectedMonth::financialYearStart());

        [$start, $end] = SelectedMonth::range();
        $this->assertSame('2026-04-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2027-03-31 23:59:59', $end->toDateTimeString());
        $this->assertSame('FY 2026-27', SelectedMonth::label());
        // Today (27 Sep 2026) falls inside it, so monthly callers get this month.
        $this->assertSame('2026-09-01', SelectedMonth::current()->toDateString());
        $this->assertTrue(SelectedMonth::isCurrentCalendarMonth());
        $this->assertSame(180, SelectedMonth::elapsedDays());
        $this->assertSame(186, SelectedMonth::remainingDays());
    }

    public function test_a_past_financial_year_resolves_monthly_callers_to_its_last_month(): void
    {
        $this->setCookies(['selected_period' => 'fy:2024']);

        $this->assertSame('2025-03-01', SelectedMonth::current()->toDateString());
        $this->assertFalse(SelectedMonth::isCurrentCalendarMonth());
        $this->assertSame(365, SelectedMonth::elapsedDays());
        $this->assertSame(0, SelectedMonth::remainingDays());
        $this->assertSame('FY 2024-25', SelectedMonth::label());
    }

    public function test_all_time_covers_everything(): void
    {
        $this->setCookies(['selected_period' => 'all', 'selected_month' => '2025-02']);

        $this->assertSame('all', SelectedMonth::mode());
        [$start, $end] = SelectedMonth::range();
        $this->assertSame('2000-01-01', $start->toDateString());
        $this->assertTrue($end->gt(now()->addYears(4)));
        $this->assertSame('All time', SelectedMonth::label());
        $this->assertSame('2026-09-01', SelectedMonth::current()->toDateString());
        $this->assertTrue(SelectedMonth::isCurrentCalendarMonth());
        // The month cookie is remembered for when the user switches back.
        $this->assertSame('2025-02-01', SelectedMonth::selectedMonth()->toDateString());
    }

    public function test_a_malformed_period_cookie_falls_back_to_the_month(): void
    {
        $this->setCookies(['selected_period' => 'fy:abcd', 'selected_month' => '2026-03']);

        $this->assertSame('month', SelectedMonth::mode());
        $this->assertSame('2026-03-01', SelectedMonth::current()->toDateString());
        $this->assertSame('Mar 2026', SelectedMonth::label());
    }

    public function test_financial_year_options_and_labels(): void
    {
        $this->assertSame('FY 2026-27', SelectedMonth::financialYearLabel(2026));
        $this->assertSame(2026, SelectedMonth::financialYearStartFor(Carbon::parse('2026-04-01')));
        $this->assertSame(2025, SelectedMonth::financialYearStartFor(Carbon::parse('2026-03-31')));

        $options = SelectedMonth::financialYearOptions();
        $this->assertArrayHasKey(2022, $options);
        $this->assertArrayHasKey(2027, $options);
        $this->assertSame('FY 2022-23', $options[2022]);
    }

    public function test_the_topbar_offers_month_financial_year_and_all_time(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->followingRedirects()->get('/admin')
            ->assertOk()
            ->assertSee('aria-label="Period"', escape: false)
            ->assertSee('Financial year')
            ->assertSee('All time')
            ->assertSee('aria-label="Month"', escape: false);

        $this->followingRedirects()
            ->withUnencryptedCookie('selected_period', 'fy:2026')
            ->get('/admin')
            ->assertOk()
            ->assertSee('aria-label="Financial year"', escape: false)
            ->assertSee('FY 2026-27')
            ->assertDontSee('aria-label="Month"', escape: false);
    }

    /**
     * The helper reads the current request's cookies, the way the existing
     * month-scope test parks the selector (request()->cookies->set()).
     *
     * @param  array<string, string>  $cookies
     */
    private function setCookies(array $cookies): void
    {
        foreach ($cookies as $name => $value) {
            request()->cookies->set($name, $value);
        }
    }
}
