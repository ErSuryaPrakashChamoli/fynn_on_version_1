<?php

namespace Tests\Feature;

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
            ->assertHasNoFormErrors();

        $settings = LoginPageSetting::current();

        $this->assertNotNull($settings->left_banner_path);
        $this->assertStringNotContainsString('{', (string) $settings->left_banner_path);
        Storage::disk('public')->assertExists($settings->left_banner_path);
        $this->assertSame('Updated via test', $settings->right_tagline);
        $this->assertNotNull($settings->left_banner_url);
    }

    public function test_non_admin_cannot_access_the_settings_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(LoginPageSettings::canAccess());
    }
}
