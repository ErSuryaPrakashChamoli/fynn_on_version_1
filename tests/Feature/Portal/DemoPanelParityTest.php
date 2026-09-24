<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Support\Demo\DemoContext;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;

/**
 * /demo is the SAME admin application, not a separate implementation.
 *
 * These prove it structurally (both panels register the identical set of
 * resources, pages and widgets, so anything added to /admin appears on
 * /demo) and behaviourally (every resource listing and page renders on
 * /demo for a demo admin, reading the demo database).
 */
class DemoPanelParityTest extends PortalBoundaryTestCase
{
    public function test_both_panels_register_the_same_resources_pages_and_widgets(): void
    {
        $admin = Filament::getPanel('admin');
        $demo = Filament::getPanel('demo');

        // No fixed count: every resource added to /admin must simply appear
        // on /demo too (e.g. the Customer Eligibility Requests resource that
        // arrived from main without any demo-side change).
        $this->assertNotEmpty($admin->getResources());
        $this->assertEqualsCanonicalizing($admin->getResources(), $demo->getResources());
        $this->assertEqualsCanonicalizing($admin->getPages(), $demo->getPages());
        $this->assertEqualsCanonicalizing($admin->getWidgets(), $demo->getWidgets());
        $this->assertSame('demo', $demo->getAuthGuard());
        $this->assertSame('web', $admin->getAuthGuard());
    }

    public function test_every_resource_listing_renders_on_demo_for_a_demo_admin(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser(role: 'Admin'));

        foreach (Filament::getPanel('demo')->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            if (! $resource::hasPage('index')) {
                continue;
            }

            $url = $resource::getUrl('index', panel: 'demo');

            $this->assertStringContainsString('/demo/', $url);
            $this->get($url)->assertSuccessful();
        }
    }

    /**
     * Each page answers exactly as it would on /admin for the same kind of
     * user: rendered when its own canAccess() admits the user, refused
     * otherwise (e.g. MyDailyCommitment is for employees, not the Admin).
     */
    public function test_every_navigable_page_renders_on_demo_for_a_demo_admin(): void
    {
        $demoAdmin = $this->makeDemoUser(role: 'Admin');
        $this->actingAsDemoUser($demoAdmin);

        $rendered = 0;

        foreach (Filament::getPanel('demo')->getPages() as $page) {
            /** @var class-string<Page> $page */
            if (! $page::shouldRegisterNavigation()) {
                continue;
            }

            $admitted = DemoContext::run(function () use ($page, $demoAdmin): bool {
                Filament::setCurrentPanel('demo');
                auth()->guard('demo')->setUser($demoAdmin);
                auth()->shouldUse('demo');

                return $page::canAccess();
            });
            auth()->shouldUse('web');

            $response = $this->get($page::getUrl(panel: 'demo'));

            if ($admitted) {
                $response->assertSuccessful();
                $rendered++;
            } else {
                $response->assertForbidden();
            }
        }

        $this->assertGreaterThan(5, $rendered);
    }

    public function test_the_demo_customer_screens_render_a_demo_customer(): void
    {
        $customer = DemoContext::run(fn () => Customer::factory()->create(['customer_name' => 'DEMO JOURNEY CUSTOMER']));

        $this->actingAsDemoUser($this->makeDemoUser(role: 'Admin'));

        $this->get('/demo/customers/'.$customer->id)->assertOk()->assertSee('DEMO JOURNEY CUSTOMER');
        $this->get('/demo/customers/'.$customer->id.'/edit')->assertOk();
    }

    public function test_demo_keeps_the_admin_look_plus_a_demo_marker(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser(role: 'Admin'));

        $this->get('/demo')
            ->assertOk()
            ->assertSee('Demo environment')
            ->assertSee('fynnedge_image_aug.png', false)
            ->assertSee('login-session-heartbeat-url', false);
    }

    public function test_the_demo_login_page_carries_the_demo_marker(): void
    {
        $this->get('/demo/login')->assertOk()->assertSee('Demo environment');
    }
}
