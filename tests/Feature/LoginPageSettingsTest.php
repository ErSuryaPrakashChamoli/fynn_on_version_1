<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LoginPageSettings;
use App\Models\LoginPageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginPageSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_a_banner_and_it_persists_as_a_real_file_path(): void
    {
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // UploadedFile::fake()->image() needs the GD extension, which isn't
        // installed here (Imagick is) -- same workaround as MyProfileTest:
        // keep Livewire's Testing\File object but overwrite its temp file
        // with real JPEG bytes from Imagick.
        $fakeBanner = UploadedFile::fake()->create('banner.jpg', 10, 'image/jpeg');
        $image = new \Imagick;
        $image->newImage(400, 600, new \ImagickPixel('steelblue'));
        $image->setImageFormat('jpeg');
        $image->writeImage($fakeBanner->getRealPath());

        Livewire::test(LoginPageSettings::class)
            ->fillForm([
                'left_banner_path' => $fakeBanner,
                'right_tagline' => 'Updated via test',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl());

        $settings = LoginPageSetting::current();

        $this->assertNotNull($settings->left_banner_path);
        $this->assertStringNotContainsString('{', (string) $settings->left_banner_path);
        Storage::disk('public')->assertExists($settings->left_banner_path);
        $this->assertSame('Updated via test', $settings->right_tagline);
        $this->assertNotNull($settings->left_banner_url);
    }

    public function test_left_heading_fields_hide_once_a_custom_banner_is_set(): void
    {
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $fakeBanner = UploadedFile::fake()->create('banner.jpg', 10, 'image/jpeg');
        $image = new \Imagick;
        $image->newImage(400, 600, new \ImagickPixel('steelblue'));
        $image->setImageFormat('jpeg');
        $image->writeImage($fakeBanner->getRealPath());

        $component = Livewire::test(LoginPageSettings::class)
            ->assertFormFieldIsVisible('left_heading')
            ->set('data.left_banner_path', $fakeBanner)
            ->assertFormFieldIsHidden('left_heading')
            ->assertFormFieldIsHidden('left_heading_size');

        $component->set('data.left_banner_path', null)
            ->assertFormFieldIsVisible('left_heading');
    }

    public function test_admin_can_change_the_company_logo_placement(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(LoginPageSettings::class)
            ->fillForm([
                'right_logo_side' => 'left',
                'right_logo_vertical_align' => 'middle',
                'right_logo_horizontal_align' => 'right',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl());

        $settings = LoginPageSetting::current();

        $this->assertSame('left', $settings->right_logo_side);
        $this->assertSame('middle', $settings->right_logo_vertical_align);
        $this->assertSame('right', $settings->right_logo_horizontal_align);
    }

    public function test_admin_can_change_the_fynn_on_logo_placement(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(LoginPageSettings::class)
            ->fillForm([
                'left_logo_side' => 'right',
                'left_logo_vertical_align' => 'bottom',
                'left_logo_horizontal_align' => 'left',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl());

        $settings = LoginPageSetting::current();

        $this->assertSame('right', $settings->left_logo_side);
        $this->assertSame('bottom', $settings->left_logo_vertical_align);
        $this->assertSame('left', $settings->left_logo_horizontal_align);
    }

    public function test_left_logo_url_is_null_with_no_default_when_no_logo_is_uploaded(): void
    {
        $settings = LoginPageSetting::current();
        $settings->update(['left_logo_path' => null]);

        $this->assertNull($settings->refresh()->left_logo_url);

        // Guest request -- an authenticated user hitting /admin/login gets
        // redirected straight to the dashboard instead of rendering it.
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('fynn-on-logo.png');
    }

    public function test_non_admin_cannot_access_the_settings_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(LoginPageSettings::canAccess());
    }
}
