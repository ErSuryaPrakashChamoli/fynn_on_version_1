<?php

namespace Tests\Feature;

use App\Filament\Pages\MyProfile;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_authenticated_users_own_employee_information(): void
    {
        $employee = Employee::factory()->create([
            'emp_name' => 'Jane Caller',
            'emp_id' => 'EMP-JANE',
        ]);
        $otherEmployee = Employee::factory()->create(['emp_name' => 'Someone Else']);

        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);

        Livewire::test(MyProfile::class)
            ->assertSee('Jane Caller')
            ->assertSee('EMP-JANE')
            ->assertDontSee('Someone Else');
    }

    public function test_saving_only_updates_the_users_own_avatar_and_nothing_else(): void
    {
        Storage::fake('public');

        $employee = Employee::factory()->create(['emp_name' => 'Jane Caller']);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);

        // UploadedFile::fake()->image() needs the GD extension, which isn't
        // installed here (Imagick is). Livewire's file-upload testing needs
        // the Testing\File object fake() produces, so keep that and just
        // overwrite its temp file with real JPEG bytes from Imagick, giving
        // FileUpload's dimension/image validation actual image data to read.
        $fakeAvatar = UploadedFile::fake()->create('avatar.jpg', 10, 'image/jpeg');
        $image = new \Imagick;
        $image->newImage(100, 100, new \ImagickPixel('steelblue'));
        $image->setImageFormat('jpeg');
        $image->writeImage($fakeAvatar->getRealPath());

        Livewire::test(MyProfile::class)
            ->fillForm([
                'avatar_path' => $fakeAvatar,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $employee->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        // Employee (Admin-managed) data must be completely untouched by a
        // profile save — the form only ever exposes the avatar field.
        $this->assertSame('Jane Caller', $employee->emp_name);
    }

    public function test_a_user_without_a_linked_employee_sees_a_graceful_fallback(): void
    {
        $user = User::factory()->create(['employee_id' => null]);

        $this->actingAs($user);

        Livewire::test(MyProfile::class)
            ->assertOk()
            ->assertSee('No employee profile is linked to your account.');
    }
}
