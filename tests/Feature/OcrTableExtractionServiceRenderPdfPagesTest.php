<?php

namespace Tests\Feature;

use App\Services\Ocr\OcrTableExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OcrTableExtractionServiceRenderPdfPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private ?string $fixturePdf = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path('app/test-ocr-pages-'.uniqid('', true));
    }

    protected function tearDown(): void
    {
        if ($this->fixturePdf !== null && file_exists($this->fixturePdf)) {
            @unlink($this->fixturePdf);
        }

        if (is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    /**
     * Builds a throwaway, real two-page PDF via Imagick so the test
     * exercises the actual pdftoppm/Imagick rendering pipeline instead of
     * mocking it away.
     */
    private function makeTwoPagePdfFixture(): string
    {
        $path = storage_path('app/test-ocr-fixture-'.uniqid('', true).'.pdf');

        $pdf = new \Imagick;

        foreach (['PAGE ONE', 'PAGE TWO'] as $label) {
            $page = new \Imagick;
            $page->newImage(400, 200, new \ImagickPixel('white'));
            $page->setImageFormat('png');
            $draw = new \ImagickDraw;
            $draw->setFontSize(24);
            $page->annotateImage($draw, 20, 100, 0, $label);
            $pdf->addImage($page);
            $page->clear();
            $page->destroy();
        }

        $pdf->setImageFormat('pdf');
        $pdf->writeImages($path, true);
        $pdf->clear();
        $pdf->destroy();

        $this->fixturePdf = $path;

        return $path;
    }

    public function test_renders_every_page_via_pdftoppm_in_a_single_pass(): void
    {
        $service = new OcrTableExtractionService;
        $pdfPath = $this->makeTwoPagePdfFixture();

        $method = new ReflectionMethod($service, 'renderPdfPages');
        $paths = $method->invoke($service, $pdfPath, $this->temporaryDirectory);

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            $this->assertFileExists($path);
            $imageInfo = @getimagesize($path);
            $this->assertIsArray($imageInfo, "Rendered page {$path} is not a readable image.");
        }
    }

    public function test_pdftoppm_helper_returns_pages_for_a_valid_pdf(): void
    {
        $service = new OcrTableExtractionService;
        $pdfPath = $this->makeTwoPagePdfFixture();

        if (! is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0775, true);
        }

        $method = new ReflectionMethod($service, 'renderPdfPagesWithPdftoppm');
        $paths = $method->invoke($service, $pdfPath, $this->temporaryDirectory);

        $this->assertIsArray($paths);
        $this->assertCount(2, $paths);
    }

    public function test_pdftoppm_helper_returns_null_on_failure_so_caller_can_fall_back(): void
    {
        $service = new OcrTableExtractionService;

        if (! is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0775, true);
        }

        $method = new ReflectionMethod($service, 'renderPdfPagesWithPdftoppm');
        $result = $method->invoke($service, '/nonexistent/path/to/document.pdf', $this->temporaryDirectory);

        $this->assertNull($result);
    }
}
