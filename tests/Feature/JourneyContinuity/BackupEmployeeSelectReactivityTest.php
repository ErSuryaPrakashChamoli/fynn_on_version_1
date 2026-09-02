<?php

namespace Tests\Feature\JourneyContinuity;

use App\Filament\Resources\CustomerJourneyDelegations\Pages\ListCustomerJourneyDelegations;
use App\Models\Employee;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Backup Employee" select combines two independent fixes:
 *
 * 1. getSearchResultsUsing() instead of a static options() array — proven
 *    correct at the PHP level for both the bulk testing helper AND a raw
 *    per-field Livewire ->set() (which mirrors exactly what a real toggle
 *    click sends), so the server-side logic was never in question.
 * 2. ->key() derived from (delegating_manager_id, is_admin_override) —
 *    forces the browser widget to be destroyed and recreated whenever
 *    either changes, so a JS-level "same search term → cached result"
 *    widget behavior can never serve a stale list after the toggle flips.
 *
 * This test proves both: the component is only found under its NEW key
 * after a dependency changes (proving the key itself changed, the
 * mechanism the remount relies on), and getSearchResults() under that key
 * reflects the current toggle state.
 */
class BackupEmployeeSelectReactivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_field_remounts_under_a_new_key_when_admin_override_changes(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $inBranch = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $outsideBranch = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $component = Livewire::test(ListCustomerJourneyDelegations::class)
            ->mountTableAction('createBackup')
            ->setTableActionData(['delegating_manager_id' => $original->id]);

        $component->assertSchemaComponentExists(
            "acting_manager_id-{$original->id}-0",
            checkComponentUsing: function (Select $select) use ($inBranch, $outsideBranch): bool {
                $results = $select->getSearchResults('');

                return array_key_exists($inBranch->id, $results)
                    && ! array_key_exists($outsideBranch->id, $results);
            },
        );

        $component->setTableActionData([
            'delegating_manager_id' => $original->id,
            'is_admin_override' => true,
        ]);

        $component->assertSchemaComponentExists(
            "acting_manager_id-{$original->id}-1",
            checkComponentUsing: function (Select $select) use ($outsideBranch): bool {
                return array_key_exists($outsideBranch->id, $select->getSearchResults(''));
            },
        );
    }

    public function test_backup_field_remounts_via_a_raw_per_field_livewire_update_matching_a_real_toggle_click(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $outsideBranch = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $component = Livewire::test(ListCustomerJourneyDelegations::class)
            ->mountTableAction('createBackup');

        $statePath = $component->instance()->{$component->instance()->getMountedActionSchemaName()}->getStatePath();

        // Two separate single-field updates, exactly as two separate real
        // user interactions (picking the Original Employee, then clicking
        // the toggle) would each independently send.
        $component->set("{$statePath}.delegating_manager_id", $original->id);
        $component->set("{$statePath}.is_admin_override", true);

        $component->assertSchemaComponentExists(
            "acting_manager_id-{$original->id}-1",
            checkComponentUsing: fn (Select $select): bool => array_key_exists($outsideBranch->id, $select->getSearchResults('')),
        );
    }
}
