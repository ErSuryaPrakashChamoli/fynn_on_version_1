<?php

namespace Tests\Feature;

use App\Services\Ocr\OcrTableExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionMethod;
use Tests\TestCase;

class OcrTableExtractionServiceColumnEscalationTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private ?string $columnImage = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path('app/test-ocr-columns-'.uniqid('', true));
        mkdir($this->temporaryDirectory, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->columnImage !== null && file_exists($this->columnImage)) {
            @unlink($this->columnImage);
        }

        if (is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    private function makeColumnImage(): string
    {
        $path = storage_path('app/test-ocr-column-'.uniqid('', true).'.png');

        $image = new \Imagick;
        $image->newImage(400, 200, new \ImagickPixel('white'));
        $image->setImageFormat('png');
        $image->writeImage($path);
        $image->clear();
        $image->destroy();

        $this->columnImage = $path;

        return $path;
    }

    private function fakeTsv(int $confidence, string $text = 'TESTWORD'): string
    {
        $header = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext";
        $row = "5\t1\t1\t1\t1\t1\t10\t10\t50\t20\t{$confidence}\t{$text}";

        return $header."\n".$row;
    }

    private function commandString(PendingProcess $process): string
    {
        return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
    }

    public function test_confident_primary_pass_skips_the_escalation_psms(): void
    {
        Process::fake([
            '*' => $this->fakeTsv(confidence: 95),
        ]);

        $service = new OcrTableExtractionService;
        $imagePath = $this->makeColumnImage();

        $method = new ReflectionMethod($service, 'runColumnsOcr');
        $result = $method->invoke(
            $service,
            ['name' => ['x1' => 0, 'x2' => 200]],
            $imagePath,
            400,
            200,
            $this->temporaryDirectory,
            null,
        );

        $this->assertNotEmpty($result['columns']['name']);

        Process::assertRanTimes(function (PendingProcess $process) {
            return str_contains($this->commandString($process), 'tesseract');
        }, times: 1);

        Process::assertRan(function (PendingProcess $process) {
            return str_contains($this->commandString($process), 'tesseract')
                && in_array('6', $process->command, true);
        });

        Process::assertDidntRun(function (PendingProcess $process) {
            $command = $this->commandString($process);

            return str_contains($command, 'tesseract')
                && (in_array('4', $process->command, true) || in_array('11', $process->command, true));
        });
    }

    public function test_low_confidence_primary_pass_escalates_to_the_extra_psms(): void
    {
        Process::fake([
            '*' => $this->fakeTsv(confidence: 10),
        ]);

        $service = new OcrTableExtractionService;
        $imagePath = $this->makeColumnImage();

        $method = new ReflectionMethod($service, 'runColumnsOcr');
        $result = $method->invoke(
            $service,
            ['name' => ['x1' => 0, 'x2' => 200]],
            $imagePath,
            400,
            200,
            $this->temporaryDirectory,
            null,
        );

        $this->assertNotEmpty($result['columns']['name']);

        Process::assertRanTimes(function (PendingProcess $process) {
            return str_contains($this->commandString($process), 'tesseract');
        }, times: 3);

        foreach (['4', '6', '11'] as $psm) {
            Process::assertRan(function (PendingProcess $process) use ($psm) {
                return str_contains($this->commandString($process), 'tesseract')
                    && in_array($psm, $process->command, true);
            });
        }
    }

    public function test_empty_output_always_escalates(): void
    {
        $service = new OcrTableExtractionService;
        $method = new ReflectionMethod($service, 'columnNeedsEscalation');

        $this->assertTrue($method->invoke($service, ''));
        $this->assertTrue($method->invoke($service, "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"));
    }

    public function test_confidence_threshold_boundary(): void
    {
        $service = new OcrTableExtractionService;
        $method = new ReflectionMethod($service, 'columnNeedsEscalation');

        $this->assertFalse($method->invoke($service, $this->fakeTsv(confidence: 95)));
        $this->assertFalse($method->invoke($service, $this->fakeTsv(confidence: 80)));
        $this->assertTrue($method->invoke($service, $this->fakeTsv(confidence: 79)));
        $this->assertTrue($method->invoke($service, $this->fakeTsv(confidence: 10)));
    }
}
