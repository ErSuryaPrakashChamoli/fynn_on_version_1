<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use App\Filament\Resources\AiDocumentSchemas\AiDocumentSchemaResource;
use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentsModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string> */
    private array $resources = [
        OcrDocumentResource::class,
        AiCustomerRecordResource::class,
        AiDocumentSchemaResource::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Manager']);
    }

    public function test_admin_can_access_documents_module_resources(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        foreach ($this->resources as $resource) {
            $this->assertTrue($resource::canAccess(), "{$resource} should be accessible to Admin");
        }
    }

    public function test_non_admin_cannot_access_documents_module_resources(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $this->actingAs($manager);

        foreach ($this->resources as $resource) {
            $this->assertFalse($resource::canAccess(), "{$resource} should not be accessible to a non-Admin");
        }
    }

    public function test_guest_cannot_access_documents_module_resources(): void
    {
        foreach ($this->resources as $resource) {
            $this->assertFalse($resource::canAccess(), "{$resource} should not be accessible without authentication");
        }
    }
}
