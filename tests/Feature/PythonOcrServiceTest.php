<?php

namespace Tests\Feature;

use App\Services\Ocr\PythonOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PythonOcrServiceTest extends TestCase
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
        $path = storage_path('app/test-python-ocr-doc-'.uniqid('', true).'.jpg');
        file_put_contents($path, 'not a real image, just needs to exist');
        $this->tempDocument = $path;

        return $path;
    }

    public function test_throws_when_document_file_does_not_exist(): void
    {
        $service = new PythonOcrService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was not found on disk');

        $service->extractText('/nonexistent/path/to/document.jpg');
    }

    public function test_throws_when_script_is_missing(): void
    {
        config(['laravel-ocr.python.script' => '/nonexistent/ocr_engine.py']);

        $service = new PythonOcrService;
        $documentPath = $this->makeTempDocument();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('script not found');

        $service->extractText($documentPath);
    }

    public function test_successful_run_returns_decoded_json(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'success' => true,
                'text' => 'hello world',
                'confidence' => 0.92,
                'document' => ['page_count' => 1],
                'pages' => [],
                'fields' => ['pan_number' => ['value' => 'ABCDE1234F', 'confidence' => 0.99]],
            ])),
        ]);

        $service = new PythonOcrService;
        $result = $service->extractText($this->makeTempDocument());

        $this->assertSame('hello world', $result['text']);
        $this->assertSame(0.92, $result['confidence']);
        $this->assertSame('ABCDE1234F', $result['fields']['pan_number']['value']);
    }

    public function test_process_failure_surfaces_stderr_in_exception(): void
    {
        Process::fake([
            '*' => Process::result(exitCode: 1, errorOutput: 'ModuleNotFoundError: paddleocr'),
        ]);

        $service = new PythonOcrService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ModuleNotFoundError: paddleocr');

        $service->extractText($this->makeTempDocument());
    }

    public function test_invalid_json_output_throws(): void
    {
        Process::fake([
            '*' => Process::result(output: 'this is not json'),
        ]);

        $service = new PythonOcrService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        $service->extractText($this->makeTempDocument());
    }

    public function test_engine_reported_failure_throws_with_its_message(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'success' => false,
                'error' => 'Unable to open PDF (corrupt or password-protected).',
            ])),
        ]);

        $service = new PythonOcrService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to open PDF');

        $service->extractText($this->makeTempDocument());
    }

    public function test_extract_table_sends_only_schema_field_shape(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'success' => true,
                'headers' => ['Created On', 'Full Name', 'Mobile Number'],
                'rows' => [
                    ['data' => ['created_on' => '2024-03-12', 'full_name' => 'RAHUL KUMAR', 'mobile_number' => '9876543210'], 'confidence' => 0.9, 'source_row' => '...'],
                ],
                'text' => 'raw text',
            ])),
        ]);

        $service = new PythonOcrService;
        $result = $service->extractTable($this->makeTempDocument(), [
            ['key' => 'created_on', 'label' => 'Created On', 'type' => 'date', 'aliases' => ['created']],
        ]);

        $this->assertSame(['Created On', 'Full Name', 'Mobile Number'], $result['headers']);
        $this->assertCount(1, $result['rows']);

        Process::assertRan(function (PendingProcess $process): bool {
            return str_contains(implode(' ', $process->command), 'ocr_engine.py');
        });
    }
}
