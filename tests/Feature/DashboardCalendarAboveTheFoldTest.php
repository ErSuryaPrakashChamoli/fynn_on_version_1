<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardFollowUpCalendarWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardCalendarAboveTheFoldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Dashboard's follow-up calendar must land inside the first screen:
     * the greeting strip precedes the heading block, the calendar widget's
     * section carries the compact-layout class, and its "New follow up"
     * action lives inside the calendar card header rather than in a row of
     * its own above the calendar.
     */
    public function test_dashboard_calendar_is_laid_out_for_the_first_screen(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // The greeting strip is PAGE_START's output, i.e. .fi-page's first
        // child, which is what the compact-spacing CSS keys on.
        $page = $this->get('/admin');
        $page->assertOk();
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="[^"]*\bfi-page\b[^"]*"[^>]*>\s*(<!--[^>]*-->\s*)*<div class="fynn-dashboard-greeting">/',
            $page->getContent(),
        );

        // Widgets are lazy-loaded, so the calendar's own markup is rendered
        // through the Livewire component rather than the page response.
        $html = Livewire::test(DashboardFollowUpCalendarWidget::class)->html();

        $this->assertStringContainsString('fynn-dashboard-calendar-section', $html);

        $cardHeaderPosition = strpos($html, 'lead-followup-calendar-card__header');
        $actionPosition = strpos($html, 'New follow up');
        $calendarPosition = strpos($html, 'fynn-server-calendar');

        $this->assertNotFalse($cardHeaderPosition);
        $this->assertNotFalse($actionPosition);
        $this->assertNotFalse($calendarPosition);
        $this->assertGreaterThan($cardHeaderPosition, $actionPosition, 'The create action should sit inside the calendar card header.');
        $this->assertLessThan($calendarPosition, $actionPosition);
    }

    /**
     * The calendar's week rows are sized from the screen: the widget measures
     * the room below the grid and theme.css reads it as the row height.
     */
    public function test_dashboard_calendar_rows_are_sized_to_fit_the_screen(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);

        $html = Livewire::test(DashboardFollowUpCalendarWidget::class)->html();

        $this->assertStringContainsString('fitToScreen()', $html);

        // Only the month itself: the weeks it spans, other months' days blank.
        $this->assertStringContainsString('fc-daygrid-body', $html);

        // Picking a day re-renders the widget; the measured row height lives on
        // this element and must survive that morph, or the last week drops off.
        $this->assertMatchesRegularExpression('/class="filament-fullcalendar fynn-server-calendar" wire:ignore\.self/', $html);

        // A clicked day is marked in the browser at once (the cell passes
        // itself in), not only after the server round trip.
        $this->assertStringContainsString('x-on:click="pickDay($el)"', $html);
        $this->assertStringContainsString('x-on:resize.window="scheduleFit()"', $html);
        $this->assertStringContainsString('--fynn-calendar-row-h', $html);

        $this->assertMatchesRegularExpression(
            '/\.fynn-dashboard-calendar-section \.filament-fullcalendar \.fc-daygrid-day-frame \{\s*min-height: 0;\s*height: var\(--fynn-calendar-row-h/',
            file_get_contents(resource_path('css/filament/admin/theme.css')),
        );
    }

    /**
     * Load speed: the calendar renders with the page (no lazy round trip)
     * and scopes follow-ups with subqueries instead of pulling every visible
     * assignment / lead id into PHP and back as IN (...) lists.
     */
    public function test_dashboard_calendar_loads_eagerly_and_scopes_with_subqueries(): void
    {
        $this->assertFalse(DashboardFollowUpCalendarWidget::isLazy());

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);

        $widget = new class extends DashboardFollowUpCalendarWidget
        {
            public function exposedScope(): Builder
            {
                return $this->scopedFollowUpQuery();
            }
        };

        DB::enableQueryLog();
        $sql = $widget->exposedScope()->toSql();

        $idLookups = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"customer_assignments"') || str_contains($query, '"leads"'));

        $this->assertEmpty($idLookups, 'Building the scope must not load assignment or lead ids into PHP.');
        $this->assertStringContainsString('from "customer_assignments"', $sql);
        $this->assertStringContainsString('from "leads"', $sql);
    }
}
