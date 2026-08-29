<?php

namespace Tests\Feature;

use App\Models\AiDocumentSchema;
use App\Services\Ocr\OcrTableExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OcrTableExtractionServicePythonEngineTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDocument = null;

    protected function tearDown(): void
    {
        if ($this->tempDocument !== null && file_exists($this->tempDocument)) {
            @unlink($this->tempDocument);
        }

        parent::tearDown();
    }

    private function makeTempDocument(): string
    {
        $path = storage_path('app/test-table-python-'.uniqid('', true).'.pdf');
        file_put_contents($path, '%PDF-1.4 fake');
        $this->tempDocument = $path;

        return $path;
    }

    public function test_extract_delegates_to_python_engine_and_skips_tesseract(): void
    {
        config(['laravel-ocr.engine' => 'python']);

        Process::fake([
            '*' => Process::result(output: json_encode([
                'success' => true,
                'headers' => ['Created On', 'Full Name', 'Mobile Number'],
                'rows' => [[
                    'data' => [
                        'created_on' => '2024-03-12',
                        'full_name' => 'RAHUL KUMAR',
                        'mobile_number' => '9876543210',
                    ],
                    'confidence' => 0.9,
                    'source_row' => '12/03/2024 RAHUL KUMAR 9876543210',
                ]],
                'text' => 'raw ocr text',
            ])),
        ]);

        $schema = AiDocumentSchema::create([
            'name' => 'Enquiry List',
            'fields' => [
                ['key' => 'created_on', 'label' => 'Created On', 'type' => 'date'],
                ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text'],
                ['key' => 'mobile_number', 'label' => 'Mobile Number', 'type' => 'mobile'],
            ],
            'is_active' => true,
        ]);

        $service = new OcrTableExtractionService;
        $result = $service->extract($this->makeTempDocument(), $schema);

        $this->assertSame(['Created On', 'Full Name', 'Mobile Number'], $result['headers']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('RAHUL KUMAR', $result['rows'][0]['data']['full_name']);

        Process::assertRanTimes(function (PendingProcess $process): bool {
            return str_contains(implode(' ', $process->command), 'ocr_engine.py');
        }, times: 1);

        Process::assertNotRan(function (PendingProcess $process): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($command, 'tesseract') || str_contains($command, 'pdftoppm');
        });
    }

    public function test_engine_config_tesseract_bypasses_python_entirely(): void
    {
        config(['laravel-ocr.engine' => 'tesseract']);

        Process::fake();

        $schema = AiDocumentSchema::create([
            'name' => 'Enquiry List',
            'fields' => [
                ['key' => 'created_on', 'label' => 'Created On', 'type' => 'date'],
                ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text'],
                ['key' => 'mobile_number', 'label' => 'Mobile Number', 'type' => 'mobile'],
            ],
            'is_active' => true,
        ]);

        $service = new OcrTableExtractionService;

        // The legacy pipeline's own success/failure against a fake PDF blob
        // isn't the point of this test (see OcrTableExtractionService*Test
        // for that) — only that OCR_ENGINE=tesseract never touches Python.
        try {
            $service->extract($this->makeTempDocument(), $schema);
        } catch (\Throwable) {
        }

        Process::assertNotRan(function (PendingProcess $process): bool {
            return str_contains(implode(' ', $process->command), 'ocr_engine.py');
        });
    }
}
