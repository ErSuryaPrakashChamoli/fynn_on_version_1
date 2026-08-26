<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root route redirects guests to the admin panel login rather than
     * rendering a page directly (two "/" routes are registered in
     * routes/web.php — a view() closure and a Route::redirect() to
     * /admin — and the redirect wins).
     */
    public function test_the_application_redirects_to_the_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
