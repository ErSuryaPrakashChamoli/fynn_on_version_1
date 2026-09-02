<?php

namespace Tests\Feature\JourneyContinuity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Route registration alone doesn't prove a page renders — this hits every
 * new Customer Journey Continuity page as an authorized Admin and checks
 * for a 200, catching table/column configuration errors that only surface
 * at render time (e.g. an exhaustive match missing a new enum case).
 */
class ContinuityPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_continuity_page_renders_successfully_for_admin(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $urls = [
            '/admin/journey-continuity-dashboard',
            '/admin/customer-journey-delegations',
            '/admin/journey-takeovers',
            '/admin/pending-manager-cases',
            '/admin/customer-sla-breaches',
            '/admin/customer-reassignments',
            '/admin/customer-journey-audits',
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }
}
