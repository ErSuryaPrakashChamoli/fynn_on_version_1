<?php

namespace App\Services\Ocr;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Runs the Python OCR engine (python/ocr/ocr_engine.py) as a one-shot
 * process per document and decodes its JSON stdout contract.
 *
 * Kept deliberately dumb: this class only knows how to invoke the script
 * and parse its output. Field mapping, business normalization, and
 * Customer/AiCustomerRecord writes stay in the existing Laravel services
 * (OcrFieldExtractionService, AiDocumentMappingService) so the Data
 * Template system remains the single source of truth for which fields
 * matter — Python only supplies OCR candidates.
 */
class PythonOcrService
{
    public static function engine(): string
    {
        return config('laravel-ocr.engine', 'python');
    }

    public static function isEnabled(): bool
    {
        return self::engine() === 'python';
    }

    /**
     * General OCR pass: full text + confidence + page metadata, plus a
     * small set of universally format-detectable fields (PAN/mobile/email)
     * for documents that have no Data Template attached.
     *
     * @return array{text: string, confidence: ?float, fields: array, pages: array, document: array, processing: array}
     */
    public function extractText(string $documentPath, ?string $documentType = null): array
    {
        return $this->run($documentPath, [
            'mode' => 'text',
            'document_type' => $documentType ?: 'general',
        ]);
    }

    /**
     * Schema-driven, repeated-row extraction (e.g. an enquiry list). The
     * schema's field definitions (key/label/aliases/type only — no
     * customer data) are sent so Python can assign OCR-detected columns
     * to the template's fields in order.
     *
     * @param  array<int, array{key?: string, label?: string, aliases?: mixed, type?: string}>  $schemaFields
     * @return array{headers: array, rows: array, text: string, pages: array, processing: array}
     */
    public function extractTable(string $documentPath, array $schemaFields): array
    {
        return $this->run($documentPath, [
            'mode' => 'table',
            'schema_fields' => array_map(
                static fn (array $field): array => [
                    'key' => (string) ($field['key'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                ],
                array_values($schemaFields),
            ),
        ]);
    }

    private function run(string $documentPath, array $config): array
    {
        if (! is_file($documentPath)) {
            throw new \RuntimeException('OCR document was not found on disk.');
        }

        $binary = (string) config('laravel-ocr.python.binary', '/usr/bin/python3');
        $script = (string) config('laravel-ocr.python.script', base_path('python/ocr/ocr_engine.py'));
        $timeout = (int) config('laravel-ocr.python.timeout', 1800);

        if (! is_file($script)) {
            throw new \RuntimeException("Python OCR engine script not found at: {$script}");
        }

        if (str_starts_with($binary, '/') && ! is_executable($binary)) {
            throw new \RuntimeException("Python OCR binary is not executable: {$binary}");
        }

        $configPath = $this->writeConfigFile($config);

        try {
            $result = Process::timeout($timeout)->run([
                $binary,
                $script,
                $documentPath,
                '--config',
                $configPath,
            ]);
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException("Python OCR engine timed out after {$timeout}s.", previous: $e);
        } finally {
            @unlink($configPath);
        }

        if ($result->failed()) {
            throw new \RuntimeException(
                'Python OCR engine failed (exit code '.$result->exitCode().'): '
                    .$this->truncate($result->errorOutput())
            );
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Python OCR engine returned invalid JSON output.');
        }

        if (($decoded['success'] ?? false) !== true) {
            throw new \RuntimeException(
                (string) ($decoded['error'] ?? 'Python OCR engine reported an unknown failure.')
            );
        }

        return $decoded;
    }

    private function writeConfigFile(array $config): string
    {
        $directory = storage_path('app/ocr-configs');

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create temporary OCR config directory.');
        }

        $path = $directory.'/'.uniqid('cfg-', true).'.json';

        if (file_put_contents($path, json_encode($config)) === false) {
            throw new \RuntimeException('Unable to write temporary OCR config file.');
        }

        return $path;
    }

    /**
     * Python never writes recognized field values or full OCR text to
     * stderr (see ocr_engine.py's log()), so this is safe to surface in
     * an exception message that may end up in OcrDocument.error_message
     * (visible in the Filament UI) — but still capped, since a Python
     * traceback can be long.
     */
    private function truncate(string $value, int $limit = 2000): string
    {
        $value = trim($value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
