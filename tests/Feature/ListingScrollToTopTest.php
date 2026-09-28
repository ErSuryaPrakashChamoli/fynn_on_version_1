<?php

namespace Tests\Feature;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Listing pages carry floating scroll-to-top / scroll-to-bottom arrows that
 * hover over the table (rendered through the RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER
 * hook); the old "Back to top" button below the pagination bar is gone.
 */
class ListingScrollToTopTest extends TestCase
{
    use RefreshDatabase;

    public function test_listings_render_the_floating_arrow_and_not_the_old_button(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // A real request: panel render hooks are registered when the panel
        // boots, which Livewire::test() does not do.
        $this->followingRedirects()
            ->get(ComplaintResource::getUrl('index'))
            ->assertOk()
            ->assertSee('fynn-table-scroll-to-top', escape: false)
            ->assertSee('aria-label="Scroll to top"', escape: false)
            ->assertSee('aria-label="Scroll to bottom"', escape: false)
            ->assertDontSee('Back to top')
            // The frozen page header / toolbar / column header script.
            ->assertSee('listing-sticky-header', escape: false);
    }

    /**
     * The filters dropdown opens from the frozen toolbar and moves with it, so
     * it must scroll on its own (capped under the toolbar) with Apply pinned.
     */
    public function test_toolbar_dropdowns_are_capped_below_the_frozen_toolbar(): void
    {
        $script = file_get_contents(public_path('js/app/listing-sticky-header.js'));
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertStringContainsString('--fynn-toolbar-bottom', $script);
        $this->assertStringContainsString('calc(100dvh - var(--fynn-toolbar-bottom', $theme);
        $this->assertStringContainsString('overscroll-behavior: contain;', $theme);
        $this->assertMatchesRegularExpression('/\.fi-ta-filters-dropdown \.fi-ta-filters-actions-ctn \{\s*position: sticky;/', $theme);
    }
}
