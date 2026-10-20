<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerFollowUpOutcomes;
use App\Filament\Pages\LeadFollowUpOutcomes;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Lead and Customer Follow-up Outcomes pages freeze like the resource
 * listings: page header, date / owner filters, table toolbar (with the
 * outcome tabs) and column header stay put while only the rows scroll.
 */
class FollowUpOutcomeFrozenHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_outcome_pages_opt_into_the_frozen_listing_with_filters_above_the_table_and_tabs_in_its_toolbar(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('Admin')));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([LeadFollowUpOutcomes::class, CustomerFollowUpOutcomes::class] as $page) {
            $this->get($page::getUrl())
                ->assertOk()
                ->assertSee('fynn-frozen-listing-page', escape: false)
                ->assertSeeInOrder(['fynn-frozen-listing-top', 'Due from', 'Owner', 'fi-ta-header-ctn', 'fynn-outcome-tabs', 'role="tablist"'], escape: false)
                ->assertSee('listing-sticky-header', escape: false);
        }
    }

    public function test_the_published_scripts_stack_the_toolbar_and_column_header_under_the_frozen_block(): void
    {
        $sticky = file_get_contents(public_path('js/app/listing-sticky-header.js'));
        $scrollNav = file_get_contents(public_path('js/app/table-scroll-nav.js'));

        foreach ([$sticky, $scrollNav] as $script) {
            $this->assertStringContainsString("'fynn-frozen-listing-page'", $script);
            $this->assertStringContainsString('--fynn-frozen-top-h', $script);
        }

        $this->assertStringContainsString("'.fynn-frozen-listing-top'", $sticky);
    }

    public function test_the_theme_freezes_the_block_and_puts_the_toolbar_below_it(): void
    {
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertStringContainsString('top: calc(var(--fynn-sticky-header-h, 0px) + var(--fynn-frozen-top-h, 0px));', $theme);
        $this->assertMatchesRegularExpression('/\.fynn-frozen-listing-page \.fi-page-content > \.fi-sc \{\s*display: flex;\s*flex-direction: column;/', $theme);
        $this->assertMatchesRegularExpression('/\.fi-grid-col:has\(\.fynn-frozen-listing-top\) \{\s*position: sticky;\s*top: var\(--fynn-sticky-header-h, 0px\);/', $theme);
    }

    /** The outcome tabs take the toolbar's centre column, between per page and search. */
    public function test_the_theme_centres_the_outcome_tabs_in_the_table_toolbar(): void
    {
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertMatchesRegularExpression('/\.fi-ta-header-toolbar:has\(> \.fynn-outcome-tabs\) \{\s*display: grid;\s*grid-template-columns: 1fr auto 1fr;/', $theme);
        $this->assertMatchesRegularExpression('/\.fi-ta-header-toolbar > \.fynn-outcome-tabs \{\s*grid-row: 1;\s*grid-column: 2;/', $theme);
    }
}
