<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavScrollControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_renders_the_nav_scroll_up_control(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertDontSee('fynn-sidebar-nav-scroll-down', false);
        $response->assertSee('fynn-sidebar-nav-scroll-up', false);
        $response->assertSee('Scroll to top of navigation');
    }
}
