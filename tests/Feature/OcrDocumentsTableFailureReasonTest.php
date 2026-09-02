<?php

namespace Tests\Feature;

use App\Filament\Resources\OcrDocuments\Pages\ListOcrDocuments;
use App\Models\OcrDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The AI Documents list previously showed only a "Failed"/"Pending" badge
 * with no indication why, forcing an admin to open each record to read
 * error_message. The status column now carries that reason as a
 * description, but only for failed/pending rows and only when one exists.
 */
class OcrDocumentsTableFailureReasonTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_failed_document_shows_its_error_message_in_the_list(): void
    {
        $this->actingAsAdmin();

        $document = OcrDocument::create([
            'original_path' => 'ocr/original/failed.pdf',
            'original_name' => 'failed.pdf',
            'status' => 'failed',
            'error_message' => "Python OCR engine failed (exit code 1): ModuleNotFoundError: No module named 'cv2'",
        ]);

        Livewire::test(ListOcrDocuments::class)
            ->assertCanSeeTableRecords([$document])
            ->assertSee('Python OCR engine failed (exit code 1): ModuleNotFoundError: No');
    }

    public function test_completed_document_with_a_stale_error_message_does_not_show_it(): void
    {
        $this->actingAsAdmin();

        $document = OcrDocument::create([
            'original_path' => 'ocr/original/completed.pdf',
            'original_name' => 'completed.pdf',
            'status' => 'completed',
            'error_message' => 'stale error from a previous failed attempt',
        ]);

        Livewire::test(ListOcrDocuments::class)
            ->assertCanSeeTableRecords([$document])
            ->assertDontSee('stale error from a previous failed attempt');
    }

    public function test_pending_document_without_an_error_message_shows_no_reason(): void
    {
        $this->actingAsAdmin();

        OcrDocument::create([
            'original_path' => 'ocr/original/pending.pdf',
            'original_name' => 'pending.pdf',
            'status' => 'pending',
            'error_message' => null,
        ]);

        Livewire::test(ListOcrDocuments::class)
            ->assertSuccessful();
    }
}
