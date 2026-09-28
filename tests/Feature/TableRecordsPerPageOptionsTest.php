<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\User;
use App\Support\TablePaginationOptions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TableRecordsPerPageOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_table_offers_the_same_records_per_page_options(): void
    {
        $this->assertSame([5, 10, 50, 100, 'all'], TablePaginationOptions::OPTIONS);

        foreach ([ListCustomers::class, ListLeads::class] as $page) {
            $options = Livewire::test($page)->instance()->getTable()->getPaginationPageOptions();

            $this->assertSame(TablePaginationOptions::OPTIONS, $options, $page);
        }
    }

    /**
     * Rendered over HTTP rather than Livewire::test(): the top selector is a
     * panel render hook, which only exists once the panel has booted.
     */
    public function test_top_and_bottom_selectors_carry_the_same_options(): void
    {
        $html = $this->get(ListCustomers::getUrl())->assertOk()->getContent();

        $markerPosition = strpos($html, 'fynn-table-records-per-page-top');
        $this->assertNotFalse($markerPosition, 'The top selector was not rendered.');

        $topSelector = substr($html, $markerPosition);
        $topSelector = substr($topSelector, 0, strpos($topSelector, '</select>'));

        foreach (TablePaginationOptions::OPTIONS as $option) {
            $this->assertStringContainsString('value="'.$option.'"', $topSelector);
        }

        $this->assertStringNotContainsString('value="25"', $topSelector);
        $this->assertStringContainsString('All', $topSelector);
    }
}
