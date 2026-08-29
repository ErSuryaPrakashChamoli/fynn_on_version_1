<?php

namespace App\Services\Ocr;

use App\Models\AiDocumentSchema;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessPoolResults;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class OcrTableExtractionService
{
    /**
     * The PSM Tesseract runs first for every column crop. The remaining
     * general-pass PSMs only fire when this pass's own output looks
     * uncertain (see columnNeedsEscalation()) — 6 (uniform block of text)
     * is the most reliable general-purpose mode for a narrow table column.
     */
    private const COLUMN_PRIMARY_PSM = 6;

    /**
     * Extra general-pass PSMs, run only on columns flagged by
     * columnNeedsEscalation(). Matches the full PSM set every column used
     * to run unconditionally, so a low-confidence column still gets
     * exactly the same passes (and therefore the same accuracy) as before.
     */
    private const COLUMN_ESCALATION_PSMS = [4, 11];

    /**
     * Below this mean word confidence (Tesseract's 0-100 scale), a
     * column's primary pass is treated as unreliable and the escalation
     * PSMs above are run for that column. Deliberately conservative (high)
     * so escalation errs toward running the extra passes whenever there is
     * genuine doubt — the cost of an unnecessary escalation is a few more
     * seconds; the cost of a missed one is wrong customer data.
     */
    private const COLUMN_CONFIDENCE_ESCALATION_THRESHOLD = 80.0;

    public function __construct(private readonly ?PythonOcrService $pythonOcr = null) {}

    public function extract(string $path, AiDocumentSchema $schema): array
    {
        $definitions = array_values($schema->getFieldDefinitions());

        if (PythonOcrService::isEnabled()) {
            return $this->extractViaPython($path, $definitions);
        }

        return $this->extractViaTesseract($path, $definitions);
    }

    /**
     * @param  array<int, array{key?: string, label?: string, type?: string}>  $definitions
     */
    private function extractViaPython(string $path, array $definitions): array
    {
        $result = ($this->pythonOcr ?? app(PythonOcrService::class))->extractTable($path, $definitions);

        return [
            'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            'rows' => is_array($result['rows'] ?? null) ? $result['rows'] : [],
            'raw_text' => (string) ($result['text'] ?? ''),
            'metadata' => is_array($result['processing'] ?? null) ? $result['processing'] : [],
        ];
    }

    /**
     * @param  array<int, array{key?: string, label?: string, type?: string}>  $definitions
     */
    private function extractViaTesseract(string $path, array $definitions): array
    {

        /*
         * The Enquiry PDF is a scanned multi-page table with exactly:
         * Created On | Full Name/Entity | Mobile Number
         *
         * Do NOT use laravel-ocr's normal PDF extraction for this layout,
         * because the Tesseract PDF driver can process only the first page.
         * Render/OCR every page ourselves and reconstruct rows from the
         * Tesseract TSV coordinates.
         */
        // if ($this->isThreeColumnContactTable($definitions)) {
        //     $multiPage = $this->extractMultiPageTable($path, $definitions);

        //     if ($multiPage !== []) {
        //         return $multiPage;
        //     }
        // }

        $coordinateFields = $this->identifyCoordinateTableFields($definitions);

        if ($coordinateFields !== null) {
            $multiPage = $this->extractMultiPageTable($path, $coordinateFields);

            if ($multiPage !== []) {
                return $multiPage;
            }

            throw new \RuntimeException(
                'Multi-page enquiry table OCR failed. No fallback was used.'
            );
        }

        $result = app('laravel-ocr')->extract($path, [
            'psm' => 6,
            'save_to_database' => false,
        ]);

        $rawText = (string) ($result['text'] ?? '');
        $lines = array_values(array_filter(
            preg_split('/\R/u', $rawText) ?: [],
            fn ($line) => trim((string) $line) !== '',
        ));

        $enquiry = $this->extractEnquiryRows($lines, $definitions);

        if ($enquiry !== []) {
            return [
                'headers' => $enquiry['headers'],
                'rows' => $enquiry['rows'],
                'raw_text' => $rawText,
                'metadata' => is_array($result['metadata'] ?? null)
                    ? $result['metadata']
                    : [],
            ];
        }

        // OCR engines can return a visually tabular document column-by-column:
        // all dates first, then all names, then all mobiles, etc. Detect that
        // layout and reconstruct customer rows by column position.
        $columnar = $this->extractColumnarRows($lines, $definitions);

        if ($columnar !== []) {
            return [
                'headers' => $columnar['headers'],
                'rows' => $columnar['rows'],
                'raw_text' => $rawText,
                'metadata' => is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
            ];
        }

        // Fallback for normal row-wise OCR output.
        $headerIndex = $this->detectHeaderLine($lines, $definitions);
        $header = $headerIndex !== null ? trim($lines[$headerIndex]) : null;
        $start = $headerIndex !== null ? $headerIndex + 1 : 0;

        $rows = [];
        foreach (array_slice($lines, $start) as $line) {
            $line = trim($line);
            if ($line === '' || $this->looksLikeHeaderLine($line, $definitions)) {
                continue;
            }

            $data = $this->parseRow($line, $definitions);
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $present = count(array_filter(
                $data,
                fn ($value) => $value !== null && trim((string) $value) !== ''
            ));
            $expected = count($definitions);

            $rows[] = [
                'data' => $data,
                'confidence' => $expected > 0 ? round($present / $expected, 4) : null,
                'source_row' => $line,
            ];
        }

        // $output = [
        //     'headers' => $header ? [$header] : [],
        //     'rows' => $rows,
        //     'raw_text' => $rawText,
        //     'metadata' => is_array($result['metadata'] ?? null)
        //         ? $result['metadata']
        //         : [],
        // ];

        // dd($output);

        return [
            'headers' => $header ? [$header] : [],
            'rows' => $rows,
            'raw_text' => $rawText,
            'metadata' => is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
        ];
    }

    /**
     * A scanned multi-page "list" table — Created On | Name | Mobile, with
     * an optional trailing text column such as Product Type — is
     * reconstructed from Tesseract's word coordinates instead of guessed
     * from line order. Line-based reconstruction (extractColumnarRows)
     * silently misaligns every row after the first cell it fails to parse,
     * because it zips columns by array position rather than by physical
     * row. This is far more likely on a large/dense scan, where Tesseract's
     * line segmentation frequently merges several physical rows onto one
     * OCR text line.
     *
     * This identifies whether the schema matches that shape (one date
     * field, one mobile field, and one or two remaining text fields) and
     * assigns each definition a role. Field position on the page is still
     * learned from the actual date/mobile word coordinates, not from
     * schema order; only which *remaining* definition is "name" vs.
     * "extra" is taken from schema order, since the trailing column
     * (e.g. Product Type) always follows the name column on these forms.
     *
     * @return array{date: array, name: array, mobile: array, extra: ?array}|null
     */
    private function identifyCoordinateTableFields(array $definitions): ?array
    {
        if (count($definitions) < 3 || count($definitions) > 4) {
            return null;
        }

        $dateField = null;
        $mobileField = null;
        $remaining = [];

        foreach ($definitions as $definition) {
            $key = Str::lower((string) ($definition['key'] ?? ''));
            $label = Str::lower((string) ($definition['label'] ?? ''));
            $type = Str::lower((string) ($definition['type'] ?? 'text'));

            if ($dateField === null && ($type === 'date' || str_contains($key, 'created') || str_contains($label, 'created'))) {
                $dateField = $definition;

                continue;
            }

            if (
                $mobileField === null
                && (
                    $type === 'mobile'
                    || str_contains($key, 'mobile')
                    || str_contains($key, 'contact')
                    || str_contains($label, 'mobile')
                    || str_contains($label, 'contact')
                )
            ) {
                $mobileField = $definition;

                continue;
            }

            $remaining[] = $definition;
        }

        if (! $dateField || ! $mobileField || $remaining === [] || count($remaining) > 2) {
            return null;
        }

        return [
            'date' => $dateField,
            'name' => $remaining[0],
            'mobile' => $mobileField,
            'extra' => $remaining[1] ?? null,
        ];
    }

    private function extractMultiPageTable(string $pdfPath, array $fields): array
    {
        $dateField = $fields['date'];
        $nameField = $fields['name'];
        $mobileField = $fields['mobile'];
        $extraField = $fields['extra'] ?? null;

        $dateKey = (string) ($dateField['key'] ?? '');
        $nameKey = (string) ($nameField['key'] ?? '');
        $mobileKey = (string) ($mobileField['key'] ?? '');
        $extraKey = $extraField !== null ? (string) ($extraField['key'] ?? '') : null;

        if ($dateKey === '' || $nameKey === '' || $mobileKey === '' || ($extraField !== null && $extraKey === '')) {
            return [];
        }

        $pagePaths = [];
        $temporaryDirectory = storage_path('app/ocr-pages-'.uniqid('', true));
        $layout = null;
        $allRows = [];
        $allRawText = [];

        try {
            $pagePaths = $this->renderPdfPages($pdfPath, $temporaryDirectory);

            logger()->info('OCR PDF pages rendered', [
                'pdf' => $pdfPath,
                'pages' => count($pagePaths),
                'paths' => $pagePaths,
            ]);

            if ($pagePaths === []) {
                return [];
            }

            foreach ($pagePaths as $pageIndex => $pagePath) {
                logger()->info('OCR processing page', [
                    'page' => $pageIndex + 1,
                    'path' => $pagePath,
                ]);

                $pageSize = @getimagesize($pagePath);

                if (! is_array($pageSize)) {
                    continue;
                }

                $width = (int) ($pageSize[0] ?? 0);
                $height = (int) ($pageSize[1] ?? 0);

                if ($width < 1 || $height < 1) {
                    continue;
                }

                /*
                 * runTesseractTsv()/runColumnsOcr() already tolerate a
                 * single pooled process failing, but this is a second,
                 * cheap safety net: on a many-page document, one page
                 * hitting an unexpected error (out of memory, a corrupt
                 * render, anything) must not throw away every other page
                 * already successfully collected in $allRows.
                 */
                try {
                    $pageRows = $this->processOnePage(
                        $pagePath,
                        $pageIndex,
                        $temporaryDirectory,
                        $dateKey,
                        $nameKey,
                        $mobileKey,
                        $width,
                        $height,
                        $layout,
                        $extraField,
                        $allRawText,
                    );
                } catch (\Throwable $e) {
                    report($e);

                    continue;
                }

                logger()->info('OCR page rows', [
                    'page' => $pageIndex + 1,
                    'rows' => count($pageRows),
                ]);

                foreach ($pageRows as $row) {
                    $row['page'] = $pageIndex + 1;
                    $allRows[] = $row;
                }
            }

            /*
             * Keep physical PDF order. The row extractor already sorts by Y
             * within each page; this secondary sort is only defensive.
             */
            usort($allRows, function (array $a, array $b): int {
                return ($a['page'] ?? 0) <=> ($b['page'] ?? 0)
                    ?: (($a['_top'] ?? 0) <=> ($b['_top'] ?? 0));
            });

            $allRows = array_map(function (array $row): array {
                unset($row['page'], $row['_top']);

                return $row;
            }, $allRows);

            if (count($allRows) < 2) {
                return [];
            }

            $headers = [
                (string) ($dateField['label'] ?? $dateKey),
                (string) ($nameField['label'] ?? $nameKey),
                (string) ($mobileField['label'] ?? $mobileKey),
            ];

            if ($extraField !== null) {
                $headers[] = (string) ($extraField['label'] ?? $extraKey);
            }

            return [
                'headers' => $headers,
                'rows' => $allRows,
                'raw_text' => implode("\n", $allRawText),
                'metadata' => [
                    'pages_processed' => count($pagePaths),
                    'rows_extracted' => count($allRows),
                    'engine' => 'tesseract-tsv-multipage-coordinate-rows',
                    'header_mode' => 'first-page-optional',
                    'column_layout' => $layout,
                ],
            ];
        } catch (\Throwable $e) {
            report($e);

            return [];
        } finally {
            foreach ($pagePaths as $pagePath) {
                @unlink($pagePath);
            }

            if (is_dir($temporaryDirectory)) {
                @rmdir($temporaryDirectory);
            }
        }
    }

    /**
     * Runs OCR for a single rendered page and returns its rows. $layout
     * and $allRawText are mutated by reference: the first usable page
     * learns $layout (sequentially — see rowsFromTsv) so every later page
     * can reuse it via the pipelined rowsFromPageWithKnownLayout() path,
     * and each page's raw text is appended for the document's raw_text
     * output.
     */
    private function processOnePage(
        string $pagePath,
        int $pageIndex,
        string $temporaryDirectory,
        string $dateKey,
        string $nameKey,
        string $mobileKey,
        int $width,
        int $height,
        ?array &$layout,
        ?array $extraField,
        array &$allRawText,
    ): array {
        if ($layout === null) {
            /*
             * The very first usable page must run its full-page OCR pass
             * on its own and wait for it — the column boundaries below
             * cannot be computed until the table layout is learned from
             * that page's words.
             */
            $ocr = $this->runTesseractTsv($pagePath);

            logger()->info('OCR page result', [
                'page' => $pageIndex + 1,
                'full_text_length' => strlen($ocr['text'] ?? ''),
                'tsv_passes' => count($ocr['tsvs'] ?? []),
            ]);

            if ($ocr['text'] !== '') {
                $allRawText[] = $ocr['text'];
            }

            $words = $this->mergeTsvWordPasses($ocr['tsvs'] ?? [$ocr['tsv']]);

            logger()->info('OCR page words', [
                'page' => $pageIndex + 1,
                'words' => count($words),
            ]);

            if ($words === []) {
                return [];
            }

            $layout = $this->detectTableLayout($words, $width, $height);

            return $this->rowsFromTsv(
                $ocr['tsvs'] ?? [$ocr['tsv']],
                $pagePath,
                $temporaryDirectory,
                $dateKey,
                $nameKey,
                $mobileKey,
                $width,
                $height,
                $layout,
                $extraField,
            );
        }

        /*
         * Layout is already known, so this page's full-page pass no
         * longer needs to happen before its column passes — both run in
         * one combined pool.
         */
        $page = $this->rowsFromPageWithKnownLayout(
            $pagePath,
            $temporaryDirectory,
            $dateKey,
            $nameKey,
            $mobileKey,
            $width,
            $height,
            $layout,
            $extraField,
        );

        if ($page['text'] !== '') {
            $allRawText[] = $page['text'];
        }

        return $page['rows'];
    }

    /**
     * Render every PDF page into an image.
     *
     * pdftoppm is tried first: it decodes the source PDF exactly once and
     * rasterizes every page in that single process. The previous default
     * path opened a brand new Imagick/PDF context per page (`new Imagick();
     * readImage($pdfPath . '[n]')`), which re-parses the *entire* source
     * file from page 0 on every iteration — on a 300-400MB, 100+ page scan
     * that repeated full-file decode was the single largest cost in the
     * whole pipeline. Imagick remains a fallback for environments where
     * poppler-utils isn't installed. Both paths render at 200 DPI (the
     * Imagick path previously used 120 DPI); the higher resolution also
     * improves Tesseract's read accuracy, not just page-rendering speed.
     */
    private function renderPdfPages(string $pdfPath, string $temporaryDirectory): array
    {
        if (! is_dir($temporaryDirectory) && ! @mkdir($temporaryDirectory, 0775, true) && ! is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Unable to create temporary OCR directory.');
        }

        $pdftoppmPaths = $this->renderPdfPagesWithPdftoppm($pdfPath, $temporaryDirectory);

        if ($pdftoppmPaths !== null) {
            return $pdftoppmPaths;
        }

        if (! class_exists(\Imagick::class)) {
            throw new \RuntimeException('Unable to render PDF pages. Install poppler-utils (pdftoppm) or the PHP Imagick extension.');
        }

        $paths = [];

        $probe = new \Imagick;
        $probe->setResolution(200, 200);
        $probe->pingImage($pdfPath);
        $pageCount = $probe->getNumberImages();
        $probe->clear();
        $probe->destroy();

        for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
            $page = new \Imagick;
            $page->setResolution(200, 200);
            $page->readImage($pdfPath.'['.$pageIndex.']');
            $page->setIteratorIndex(0);
            $page->setImageFormat('png');
            $page->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $page->setImageCompressionQuality(95);
            $page->sharpenImage(0, 1);

            $output = $temporaryDirectory.'/page-'.str_pad((string) ($pageIndex + 1), 4, '0', STR_PAD_LEFT).'.png';
            $page->writeImage($output);
            $page->clear();
            $page->destroy();

            $paths[] = $output;
        }

        return $paths;
    }

    /**
     * Renders every page of $pdfPath with a single pdftoppm invocation.
     * Returns null (rather than throwing) when poppler-utils isn't
     * available or the render fails, so the caller can fall back to
     * Imagick instead of failing the whole document.
     */
    private function renderPdfPagesWithPdftoppm(string $pdfPath, string $temporaryDirectory): ?array
    {
        $prefix = $temporaryDirectory.'/page';

        $process = Process::timeout(600)->run([
            'pdftoppm',
            '-r',
            '200',
            '-gray',
            '-png',
            $pdfPath,
            $prefix,
        ]);

        if ($process->failed()) {
            return null;
        }

        $paths = glob($prefix.'-*.png') ?: [];

        if ($paths === []) {
            return null;
        }

        natsort($paths);

        return array_values($paths);
    }

    /**
     * Runs each PSM pass as its own tesseract process, in parallel. These
     * passes are fully independent (each reads the same image once and
     * writes to its own stdout), so running them concurrently instead of
     * one after another cuts wall-clock time roughly in proportion to the
     * pass count with no change in output — this matters most on a
     * large/multi-page scan, where every page pays this cost.
     */
    private function runTesseractTsv(string $imagePath): array
    {
        $psms = [6, 11];
        $results = $this->runPoolTolerantly(function (Pool $pool) use ($imagePath, $psms) {
            foreach ($psms as $psm) {
                $pool->as((string) $psm)->timeout(180)->command([
                    'tesseract',
                    $imagePath,
                    'stdout',
                    '--psm',
                    (string) $psm,
                    '-l',
                    'eng',
                    'tsv',
                ]);
            }
        });

        $passes = [];

        if ($results !== null) {
            foreach ($psms as $psm) {
                $result = $results[(string) $psm];

                if (! $result->failed()) {
                    $passes[] = $result->output();
                }
            }
        }

        return [
            'tsv' => $passes[0] ?? '',
            'tsvs' => $passes,
            'text' => $passes !== [] ? $this->tsvToText($passes[0]) : '',
        ];
    }

    /**
     * Runs a process pool but never lets it take down the caller. Under
     * heavy machine load (many pooled tesseract processes competing for
     * few CPU cores — expected on a large scan) a single pooled process
     * can exceed its own ->timeout() even while the rest of the pool is
     * still healthy. Laravel's Process::pool()->run() does not turn that
     * into a normal "failed" result for just that one process — it
     * throws, which aborts the ENTIRE pool call and, left uncaught,
     * bubbles all the way up through extractMultiPageTable's catch
     * block, discarding every page already successfully processed on a
     * many-page document. Treating one bad pool round as "no results this
     * round" (letting the affected page contribute less, or nothing)
     * beats losing the whole document.
     */
    private function runPoolTolerantly(callable $callback): ?ProcessPoolResults
    {
        try {
            return Process::pool($callback)->run();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function tsvToText(string $tsv): string
    {
        $lines = preg_split('/\R/u', trim($tsv)) ?: [];
        if ($lines === []) {
            return '';
        }

        $header = str_getcsv(array_shift($lines), "\t", '"', '\\');
        $indexes = array_flip($header);

        if (! isset($indexes['text'])) {
            return '';
        }

        $words = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, "\t", '"', '\\');
            $text = trim((string) ($columns[$indexes['text']] ?? ''));
            if ($text !== '') {
                $words[] = $text;
            }
        }

        return implode(' ', $words);
    }

    private function parseTsvWords(string $tsv, int $minimumConfidence = 0): array
    {
        $lines = preg_split('/\R/u', trim($tsv)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), "\t", '"', '\\');
        $indexes = array_flip($header);

        foreach (['left', 'top', 'width', 'height', 'conf', 'text'] as $required) {
            if (! array_key_exists($required, $indexes)) {
                return [];
            }
        }

        $words = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, "\t", '"', '\\');
            $text = trim((string) ($columns[$indexes['text']] ?? ''));
            if ($text === '') {
                continue;
            }

            $confidence = (float) ($columns[$indexes['conf']] ?? -1);
            if ($confidence >= 0 && $confidence < $minimumConfidence) {
                continue;
            }

            $left = (int) ($columns[$indexes['left']] ?? 0);
            $top = (int) ($columns[$indexes['top']] ?? 0);
            $width = (int) ($columns[$indexes['width']] ?? 0);
            $height = (int) ($columns[$indexes['height']] ?? 0);

            $words[] = [
                'text' => $text,
                'left' => $left,
                'right' => $left + $width,
                'top' => $top,
                'bottom' => $top + $height,
                'center_y' => $top + ($height / 2),
                'confidence' => $confidence,
            ];
        }

        return $words;
    }

    private function mergeTsvWordPasses(array $tsvs): array
    {
        $merged = [];

        foreach ($tsvs as $passIndex => $tsv) {
            $words = $this->parseTsvWords($tsv, $passIndex === 0 ? 8 : 0);

            foreach ($words as $word) {
                $bestIndex = null;
                $bestDistance = PHP_INT_MAX;

                foreach ($merged as $index => $existing) {
                    $distance = abs($word['left'] - $existing['left'])
                        + abs($word['center_y'] - $existing['center_y']);

                    if (
                        $distance <= 35
                        && abs($word['left'] - $existing['left']) <= 45
                        && abs($word['center_y'] - $existing['center_y']) <= 22
                        && $distance < $bestDistance
                    ) {
                        $bestIndex = $index;
                        $bestDistance = $distance;
                    }
                }

                if ($bestIndex === null) {
                    $merged[] = $word;

                    continue;
                }

                /* Keep the higher-confidence OCR representation. */
                if ($word['confidence'] > $merged[$bestIndex]['confidence']) {
                    $merged[$bestIndex] = $word;
                }
            }
        }

        usort(
            $merged,
            fn (array $a, array $b) => $a['center_y'] <=> $b['center_y'] ?: $a['left'] <=> $b['left']
        );

        return $merged;
    }

    /**
     * Learn relative column positions from the first useful page.
     * Header text is optional. Date/mobile anchors are used as a fallback.
     */
    private function detectTableLayout(array $words, int $pageWidth, int $pageHeight): array
    {
        $dateLefts = [];
        $dateRights = [];
        $mobileLefts = [];
        $mobileRights = [];

        foreach ($words as $word) {
            $text = (string) $word['text'];
            $left = (int) $word['left'];

            if ($left <= (int) round($pageWidth * 0.45) && $this->containsDatePattern($text)) {
                $dateLefts[] = $left;
                $dateRights[] = (int) $word['right'];
            }

            if ($left >= (int) round($pageWidth * 0.55) && $this->extractMobileValue($text) !== null) {
                $mobileLefts[] = $left;
                $mobileRights[] = (int) $word['right'];
            }
        }

        $dateRight = $dateRights !== [] ? (int) round($this->median($dateRights)) : (int) round($pageWidth * 0.30);
        $mobileLeft = $mobileLefts !== [] ? (int) round($this->median($mobileLefts)) : (int) round($pageWidth * 0.72);
        $mobileRight = $mobileRights !== [] ? (int) round($this->median($mobileRights)) : (int) round($mobileLeft + ($pageWidth * 0.12));

        $dateNameBoundary = (int) round($dateRight + (($mobileLeft - $dateRight) * 0.25));
        /*
         * Mobile OCR may start noticeably earlier on a noisy scan. Keep a
         * generous right-side search area; we identify the mobile by its
         * 10-digit value, not by column position alone.
         */
        $mobileBoundary = max(
            (int) round($pageWidth * 0.58),
            (int) round($mobileLeft - ($pageWidth * 0.10))
        );

        /*
         * Only used when the schema has a fourth, trailing text column
         * (e.g. Product Type) after the mobile number. The mobile number's
         * own right edge — not a fixed fraction of the page — marks where
         * that column starts, since mobile-number width does not vary.
         */
        $extraBoundary = min(
            $pageWidth,
            max($mobileBoundary + 1, (int) round($mobileRight + ($pageWidth * 0.02)))
        );

        return [
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'date_right' => $dateRight,
            'date_name_boundary' => $dateNameBoundary,
            'mobile_boundary' => $mobileBoundary,
            'mobile_left' => $mobileLeft,
            'extra_boundary' => $extraBoundary,
            'relative_date_name_boundary' => $pageWidth > 0 ? $dateNameBoundary / $pageWidth : 0.35,
            'relative_mobile_boundary' => $pageWidth > 0 ? $mobileBoundary / $pageWidth : 0.62,
            'relative_extra_boundary' => $pageWidth > 0 ? $extraBoundary / $pageWidth : 0.85,
        ];
    }

    /**
     * Reconstruct rows from a physical table grid.
     *
     * The previous implementation created row anchors only when Tesseract
     * recognised a date/mobile word on the full page. On noisy scans that
     * produced only a fraction of the real rows (for example 10 instead of
     * 69 across three pages). This implementation first detects the repeating
     * row grid from a dedicated date/mobile-column OCR pass, fills missing
     * grid positions using the median row pitch, and then reads each column
     * inside the same physical row band.
     *
     * Headers are ignored after the first detected body row, so page 2+ can
     * have no header at all.
     */
    private function rowsFromTsv(
        array $tsvs,
        string $imagePath,
        string $temporaryDirectory,
        string $dateKey,
        string $nameKey,
        string $mobileKey,
        int $pageWidth,
        int $pageHeight,
        ?array $layout = null,
        ?array $extraField = null,
    ): array {
        $fullWords = $this->mergeTsvWordPasses($tsvs);
        if ($fullWords === []) {
            return [];
        }

        $layout ??= $this->detectTableLayout($fullWords, $pageWidth, $pageHeight);

        $extraKey = $extraField !== null ? (string) ($extraField['key'] ?? '') : null;
        if ($extraKey === '') {
            $extraKey = null;
        }

        $boundaries = $this->computeColumnBoundaries($layout, $pageWidth, $extraKey);

        $columnWords = $this->runColumnsOcr(
            $boundaries['bounds'],
            $imagePath,
            $pageWidth,
            $pageHeight,
            $temporaryDirectory,
        )['columns'];

        return $this->reconstructRowsFromWords(
            $tsvs,
            $fullWords,
            $columnWords['date'],
            $columnWords['name'],
            $columnWords['mobile'],
            $columnWords['extra'] ?? [],
            $dateKey,
            $nameKey,
            $mobileKey,
            $extraKey,
            $extraField,
            $boundaries['date_boundary'],
            $boundaries['mobile_boundary'],
            $boundaries['extra_boundary'],
            $pageHeight,
        );
    }

    /**
     * Runs a page's full-page OCR pass and its column OCR passes together
     * in one process pool, instead of the separate sequential pool that
     * rowsFromTsv() needs when the table layout isn't known yet. Only
     * valid once layout has already been learned from an earlier page.
     *
     * @return array{text: string, rows: array<int, array<string, mixed>>}
     */
    private function rowsFromPageWithKnownLayout(
        string $pagePath,
        string $temporaryDirectory,
        string $dateKey,
        string $nameKey,
        string $mobileKey,
        int $pageWidth,
        int $pageHeight,
        array $layout,
        ?array $extraField,
    ): array {
        $extraKey = $extraField !== null ? (string) ($extraField['key'] ?? '') : null;
        if ($extraKey === '') {
            $extraKey = null;
        }

        $boundaries = $this->computeColumnBoundaries($layout, $pageWidth, $extraKey);

        $result = $this->runColumnsOcr(
            $boundaries['bounds'],
            $pagePath,
            $pageWidth,
            $pageHeight,
            $temporaryDirectory,
            $pagePath,
        );

        $columnWords = $result['columns'];
        $fullPageTsvs = $result['full_page_tsvs'];
        $fullWords = $this->mergeTsvWordPasses($fullPageTsvs);
        $fullText = $fullPageTsvs !== [] ? $this->tsvToText($fullPageTsvs[0]) : '';

        $rows = $this->reconstructRowsFromWords(
            $fullPageTsvs,
            $fullWords,
            $columnWords['date'],
            $columnWords['name'],
            $columnWords['mobile'],
            $columnWords['extra'] ?? [],
            $dateKey,
            $nameKey,
            $mobileKey,
            $extraKey,
            $extraField,
            $boundaries['date_boundary'],
            $boundaries['mobile_boundary'],
            $boundaries['extra_boundary'],
            $pageHeight,
        );

        return ['text' => $fullText, 'rows' => $rows];
    }

    /**
     * Column boundaries (in pixels) derived from a learned table layout.
     * Shared between rowsFromTsv() (layout just learned, page 1) and
     * rowsFromPageWithKnownLayout() (layout already known, page 2+) so
     * both build identical column crops from the same layout.
     *
     * @return array{bounds: array<string, array{x1: int, x2: int}>, date_boundary: int, mobile_boundary: int, extra_boundary: ?int}
     */
    private function computeColumnBoundaries(array $layout, int $pageWidth, ?string $extraKey): array
    {
        $dateBoundary = (int) round(
            $pageWidth * (float) ($layout['relative_date_name_boundary'] ?? 0.35)
        );
        $mobileBoundary = (int) round(
            $pageWidth * (float) ($layout['relative_mobile_boundary'] ?? 0.70)
        );
        $extraBoundary = $extraKey !== null
            ? (int) round($pageWidth * (float) ($layout['relative_extra_boundary'] ?? 0.85))
            : null;

        /*
         * Every column used to be cropped and OCR'd (each already its own
         * internal pool of PSM passes) one after another — date, then
         * name, then mobile, then extra. Those columns are just as
         * independent of each other as the passes within one column are,
         * so batching every column's passes into a single process pool
         * turns a page's column OCR into roughly one pass' worth of
         * wall-clock time total instead of one pass' worth *per column*.
         */
        $bounds = [
            'date' => [
                'x1' => 0,
                'x2' => max(1, $dateBoundary),
            ],
            'name' => [
                'x1' => min($pageWidth - 1, max(0, (int) round($dateBoundary * 0.88))),
                'x2' => min($pageWidth, max($dateBoundary + 1, $mobileBoundary)),
            ],
            'mobile' => [
                'x1' => min($pageWidth - 1, $mobileBoundary),
                'x2' => $extraBoundary !== null
                    ? min($pageWidth, max($mobileBoundary + 1, $extraBoundary))
                    : $pageWidth,
            ],
        ];

        if ($extraKey !== null) {
            $bounds['extra'] = [
                'x1' => min($pageWidth - 1, max(0, $extraBoundary - (int) round($pageWidth * 0.03))),
                'x2' => $pageWidth,
            ];
        }

        return [
            'bounds' => $bounds,
            'date_boundary' => $dateBoundary,
            'mobile_boundary' => $mobileBoundary,
            'extra_boundary' => $extraBoundary,
        ];
    }

    /**
     * Reconstructs table rows from already-fetched OCR words — shared by
     * rowsFromTsv() and rowsFromPageWithKnownLayout(), which differ only
     * in how (and how many process pools) they use to obtain those words.
     */
    private function reconstructRowsFromWords(
        array $tsvs,
        array $fullWords,
        array $dateWords,
        array $nameWords,
        array $mobileWords,
        array $extraWords,
        string $dateKey,
        string $nameKey,
        string $mobileKey,
        ?string $extraKey,
        ?array $extraField,
        int $dateBoundary,
        int $mobileBoundary,
        ?int $extraBoundary,
        int $pageHeight,
    ): array {
        $rowCenters = $this->detectPhysicalRowCenters(
            array_merge($dateWords, $nameWords, $mobileWords, $extraWords),
            $pageHeight,
        );

        if (count($rowCenters) < 2) {
            // Last-resort fallback to the full-page OCR anchors.
            $rowCenters = $this->detectPhysicalRowCenters(
                array_merge($dateWords, $nameWords, $mobileWords, $extraWords),
                $pageHeight,
            );

            $anchors = $this->buildRowAnchors(
                $tsvs,
                $fullWords,
                $dateBoundary,
                $mobileBoundary,
                $pageHeight,
            );
            $rowCenters = array_values(array_map(
                fn (array $anchor): float => (float) $anchor['top'],
                $anchors,
            ));
        }

        if ($rowCenters === []) {
            return [];
        }

        $rowPitch = $this->estimateGridPitch($rowCenters);
        // $tolerance = max(24.0, min(48.0, $rowPitch * 0.42));
        $tolerance = max(12.0, min(30.0, $rowPitch * 0.30));

        $rows = [];

        foreach ($rowCenters as $rowCenter) {
            $dateRowWords = $this->wordsNearRow($dateWords, $rowCenter, $tolerance);
            $nameRowWords = $this->wordsNearRow($nameWords, $rowCenter, $tolerance);
            $mobileRowWords = $this->wordsNearRow($mobileWords, $rowCenter, $tolerance);
            $extraRowWords = $extraKey !== null ? $this->wordsNearRow($extraWords, $rowCenter, $tolerance) : [];

            // Full-page OCR is useful as a fallback when a column crop misses
            // a word because of the scan noise.
            $fullRowWords = $this->wordsNearRow($fullWords, $rowCenter, $tolerance);

            $dateText = $this->joinWords($dateRowWords);
            $nameText = $this->joinWords($nameRowWords);
            $mobileText = $this->joinWords($mobileRowWords);
            $extraText = $extraKey !== null ? $this->joinWords($extraRowWords) : '';

            if ($dateText === '') {
                $dateText = $this->joinWords(array_values(array_filter(
                    $fullRowWords,
                    fn (array $word): bool => $word['left'] < $dateBoundary
                )));
            }

            if ($nameText === '') {
                $nameText = $this->joinWords(array_values(array_filter(
                    $fullRowWords,
                    fn (array $word): bool => $word['left'] >= $dateBoundary
                        && $word['left'] < $mobileBoundary
                )));
            }

            if ($mobileText === '') {
                $mobileText = $this->joinWords(array_values(array_filter(
                    $fullRowWords,
                    fn (array $word): bool => $word['left'] >= $mobileBoundary
                        && ($extraBoundary === null || $word['left'] < $extraBoundary)
                )));
            }

            if ($extraKey !== null && $extraText === '') {
                $extraText = $this->joinWords(array_values(array_filter(
                    $fullRowWords,
                    fn (array $word): bool => $word['left'] >= $extraBoundary
                )));
            }

            $date = $this->extractDateValue($dateText);
            $mobile = $this->extractMobileValue($mobileText);

            if ($mobile === null) {
                $mobile = $this->extractMobileValueFromRightSide(
                    $mobileRowWords !== [] ? $mobileRowWords : $fullRowWords,
                    $mobileBoundary,
                );
            }

            $name = $this->cleanCoordinateName($nameRowWords);
            if ($name === null) {
                $fallbackNameWords = array_values(array_filter(
                    $fullRowWords,
                    fn (array $word): bool => $word['left'] >= $dateBoundary
                        && $word['left'] < $mobileBoundary
                ));
                $name = $this->cleanCoordinateName($fallbackNameWords);
            }

            $extra = $extraKey !== null ? $this->cleanCoordinateText($extraText) : null;

            $headerCheckDefinitions = [
                ['key' => $dateKey, 'label' => 'Created On', 'type' => 'date'],
                ['key' => $nameKey, 'label' => 'Full Name/Entity', 'type' => 'text'],
                ['key' => $mobileKey, 'label' => 'Mobile Number', 'type' => 'mobile'],
            ];
            $headerCheckText = $dateText.' '.$nameText.' '.$mobileText;

            if ($extraKey !== null) {
                $headerCheckDefinitions[] = [
                    'key' => $extraKey,
                    'label' => (string) ($extraField['label'] ?? 'Product Type'),
                    'type' => 'text',
                ];
                $headerCheckText .= ' '.$extraText;
            }

            // Ignore the header and completely empty/noise rows.
            if ($this->looksLikeHeaderLine($headerCheckText, $headerCheckDefinitions)) {
                continue;
            }

            if ($date === null && $mobile === null && $name === null && ($extraKey === null || $extra === null)) {
                continue;
            }

            $data = [
                $dateKey => $date,
                $nameKey => $name,
                $mobileKey => $mobile,
            ];

            if ($extraKey !== null) {
                $data[$extraKey] = $extra;
            }

            $expectedFieldCount = $extraKey !== null ? 4 : 3;

            $present = count(array_filter(
                $data,
                fn ($value) => $value !== null && trim((string) $value) !== ''
            ));

            $rows[] = [
                'data' => $data,
                'confidence' => round($present / $expectedFieldCount, 4),
                'source_row' => trim(implode(' | ', array_filter([
                    $dateText,
                    $nameText,
                    $mobileText,
                    $extraKey !== null ? $extraText : null,
                ]))),
                '_top' => (int) round($rowCenter),
            ];
        }

        /*
         * Deduplicate only genuinely repeated OCR detections. Do not use the
         * mobile number as the sole key because a malformed OCR value can
         * accidentally match another row. The row centre remains part of the
         * fingerprint within a page.
         */
        $deduplicated = [];
        $seen = [];

        foreach ($rows as $row) {
            $mobileValue = preg_replace('/\D+/', '', (string) ($row['data'][$mobileKey] ?? ''));
            $fingerprint = $mobileValue !== ''
                ? 'm:'.$mobileValue.':y:'.(int) ($row['_top'] ?? 0)
                : 'y:'.(int) ($row['_top'] ?? 0).':r:'.md5((string) ($row['source_row'] ?? ''));

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $deduplicated[] = $row;
        }

        return $deduplicated;
    }

    private function cropColumnImage(
        string $imagePath,
        int $x1,
        int $x2,
        int $pageWidth,
        int $pageHeight,
        string $temporaryDirectory,
        string $column,
    ): ?array {
        $x1 = max(0, min($pageWidth - 1, $x1));
        $x2 = max($x1 + 1, min($pageWidth, $x2));
        $cropWidth = $x2 - $x1;

        $cropPath = $temporaryDirectory.'/crop-'.$column.'.png';

        if (class_exists(\Imagick::class)) {
            $image = new \Imagick($imagePath);
            $image->cropImage($cropWidth, $pageHeight, $x1, 0);
            $image->setImagePage(0, 0, 0, 0);
            $image->setImageFormat('png');
            $image->writeImage($cropPath);
            $image->clear();
            $image->destroy();
        } else {
            $process = Process::timeout(60)->run([
                'magick',
                $imagePath,
                '-crop',
                $cropWidth.'x'.$pageHeight.'+'.$x1.'+0',
                '+repage',
                $cropPath,
            ]);

            if ($process->failed()) {
                return null;
            }
        }

        return ['path' => $cropPath, 'x1' => $x1];
    }

    /**
     * Runs OCR for every table column of a page in a single process pool,
     * rather than fully cropping and OCR'ing one column before starting
     * the next. Columns are exactly as independent of each other as the
     * PSM passes within one column are (each just reads its own crop and
     * is only combined afterwards), so batching every column's passes
     * into one pool turns a page's column OCR into roughly one pass'
     * worth of wall-clock time total, not one pass' worth *per column*.
     *
     * Only the primary PSM (self::COLUMN_PRIMARY_PSM) runs per column in
     * this first pool; the extra general-pass PSMs
     * (self::COLUMN_ESCALATION_PSMS) are deferred to a second, smaller
     * pool that only includes columns whose primary pass looks uncertain
     * (see columnNeedsEscalation()). A typical clean scan never needs
     * that second pool at all — most of a page's column fan-out
     * (previously 3 general passes per column, every column, every page)
     * is skipped — while a genuinely noisy column still ends up running
     * the exact same PSM set as before, so accuracy on hard pages is
     * unchanged.
     *
     * $fullPagePath optionally folds the page's own full-page OCR passes
     * (normally run separately beforehand, via runTesseractTsv) into this
     * SAME pool. That's only safe once the table layout is already known:
     * the very first page still needs its full-page words *first* to
     * derive the column boundaries below, but every page after that
     * reuses page 1's layout, so its full-page pass and its column passes
     * don't depend on each other's output at all and can run together —
     * removing a full sequential pool round-trip from every page after
     * the first, which is nearly the entire page count on a large scan.
     *
     * @param  array<string, array{x1: int, x2: int}>  $columns
     * @return array{columns: array<string, array<int, array<string, mixed>>>, full_page_tsvs: array<int, string>}
     */
    private function runColumnsOcr(
        array $columns,
        string $imagePath,
        int $pageWidth,
        int $pageHeight,
        string $temporaryDirectory,
        ?string $fullPagePath = null,
    ): array {
        $crops = [];

        foreach ($columns as $column => $bounds) {
            $crop = $this->cropColumnImage(
                $imagePath,
                $bounds['x1'],
                $bounds['x2'],
                $pageWidth,
                $pageHeight,
                $temporaryDirectory,
                $column,
            );

            if ($crop !== null) {
                $crops[$column] = $crop;
            }
        }

        $wordsByColumn = array_fill_keys(array_keys($columns), []);

        if ($crops === [] && $fullPagePath === null) {
            return ['columns' => $wordsByColumn, 'full_page_tsvs' => []];
        }

        $digitPsms = [6, 4];
        $fullPagePsms = [6, 11];

        $primaryResults = $this->runPoolTolerantly(function (Pool $pool) use ($crops, $digitPsms, $fullPagePath, $fullPagePsms) {
            foreach ($crops as $column => $crop) {
                $pool->as($column.'::general-'.self::COLUMN_PRIMARY_PSM)->timeout(120)->command([
                    'tesseract',
                    $crop['path'],
                    'stdout',
                    '--psm',
                    (string) self::COLUMN_PRIMARY_PSM,
                    '-l',
                    'eng',
                    'tsv',
                ]);

                /*
                 * Mobile numbers are pure digits, but the general "eng"
                 * pass reads the crop against a full alphanumeric
                 * alphabet and occasionally drifts onto a visually similar
                 * letter/digit (e.g. 8 -> 0, 3 -> 9). A digit-whitelisted
                 * pass removes that ambiguity. Always run — cheap, and
                 * mobile-number accuracy is the field this pipeline cares
                 * about most.
                 */
                if ($column === 'mobile') {
                    foreach ($digitPsms as $psm) {
                        $pool->as($column.'::digit-'.$psm)->timeout(120)->command([
                            'tesseract',
                            $crop['path'],
                            'stdout',
                            '--psm',
                            (string) $psm,
                            '-l',
                            'eng',
                            '-c',
                            'tessedit_char_whitelist=0123456789',
                            'tsv',
                        ]);
                    }
                }
            }

            if ($fullPagePath !== null) {
                foreach ($fullPagePsms as $psm) {
                    $pool->as('__fullpage__::'.$psm)->timeout(180)->command([
                        'tesseract',
                        $fullPagePath,
                        'stdout',
                        '--psm',
                        (string) $psm,
                        '-l',
                        'eng',
                        'tsv',
                    ]);
                }
            }
        });

        if ($primaryResults === null) {
            foreach ($crops as $crop) {
                @unlink($crop['path']);
            }

            return ['columns' => $wordsByColumn, 'full_page_tsvs' => []];
        }

        $generalOutputsByColumn = [];
        $columnsToEscalate = [];

        foreach ($crops as $column => $crop) {
            $result = $primaryResults[$column.'::general-'.self::COLUMN_PRIMARY_PSM];
            $output = ! $result->failed() ? $result->output() : '';
            $generalOutputsByColumn[$column] = $output !== '' ? [$output] : [];

            if ($this->columnNeedsEscalation($output)) {
                $columnsToEscalate[] = $column;
            }
        }

        if ($columnsToEscalate !== []) {
            $escalationResults = $this->runPoolTolerantly(function (Pool $pool) use ($crops, $columnsToEscalate) {
                foreach ($columnsToEscalate as $column) {
                    $crop = $crops[$column];

                    foreach (self::COLUMN_ESCALATION_PSMS as $psm) {
                        $pool->as($column.'::general-'.$psm)->timeout(120)->command([
                            'tesseract',
                            $crop['path'],
                            'stdout',
                            '--psm',
                            (string) $psm,
                            '-l',
                            'eng',
                            'tsv',
                        ]);
                    }
                }
            });

            if ($escalationResults !== null) {
                foreach ($columnsToEscalate as $column) {
                    foreach (self::COLUMN_ESCALATION_PSMS as $psm) {
                        $result = $escalationResults[$column.'::general-'.$psm];

                        if (! $result->failed()) {
                            $generalOutputsByColumn[$column][] = $result->output();
                        }
                    }
                }
            }
        }

        foreach ($crops as $column => $crop) {
            $x1 = $crop['x1'];
            $words = [];

            foreach ($generalOutputsByColumn[$column] as $output) {
                foreach ($this->parseTsvWords($output, 0) as $word) {
                    $word['left'] += $x1;
                    $word['right'] += $x1;
                    $words[] = $word;
                }
            }

            /*
             * The digit-only readings rarely text-match the general
             * pass's readings at the same spot, so they must be merged by
             * position (not by equal text) before the normal
             * text-equality dedupe runs.
             */
            if ($column === 'mobile') {
                $digitWords = [];

                foreach ($digitPsms as $psm) {
                    $result = $primaryResults[$column.'::digit-'.$psm];

                    if ($result->failed()) {
                        continue;
                    }

                    foreach ($this->parseTsvWords($result->output(), 0) as $word) {
                        $word['left'] += $x1;
                        $word['right'] += $x1;
                        $digitWords[] = $word;
                    }
                }

                $words = $this->preferDigitOnlyReadings($words, $digitWords);
            }

            @unlink($crop['path']);

            $wordsByColumn[$column] = $this->dedupeCoordinateWords($words);
        }

        $fullPageTsvs = [];

        if ($fullPagePath !== null) {
            foreach ($fullPagePsms as $psm) {
                $result = $primaryResults['__fullpage__::'.$psm];

                if (! $result->failed()) {
                    $fullPageTsvs[] = $result->output();
                }
            }
        }

        return ['columns' => $wordsByColumn, 'full_page_tsvs' => $fullPageTsvs];
    }

    /**
     * Decides whether a column's primary OCR pass is uncertain enough to
     * warrant re-running it under the extra PSM modes. Empty output (no
     * words recognised at all) always escalates, since that is at least
     * as likely to be the wrong PSM for this crop's layout as a genuinely
     * blank column.
     */
    private function columnNeedsEscalation(string $tsvOutput): bool
    {
        $words = $this->parseTsvWords($tsvOutput, 0);

        if ($words === []) {
            return true;
        }

        $confidences = array_values(array_filter(
            array_map(fn (array $word): float => (float) $word['confidence'], $words),
            fn (float $confidence): bool => $confidence >= 0,
        ));

        if ($confidences === []) {
            return true;
        }

        $mean = array_sum($confidences) / count($confidences);

        return $mean < self::COLUMN_CONFIDENCE_ESCALATION_THRESHOLD;
    }

    /**
     * Overrides a general-pass word's text with the digit-only pass's
     * reading, but only when that is safe:
     *   - the general-pass word had no usable digits at all (fills a gap), or
     *   - both readings are essentially "the same number" (small edit
     *     distance), i.e. a genuine digit-level correction.
     * The digit-only pass segments the crop independently, so its bounding
     * boxes do not always align to the same physical word as the general
     * pass; blindly trusting position proximity can attach a completely
     * different row's number to this word. Requiring agreement (or an
     * empty original) avoids that cross-row corruption while still fixing
     * single-digit misreads (e.g. 8 <-> 0, 3 <-> 9) and filling values the
     * general pass missed entirely.
     */
    private function preferDigitOnlyReadings(array $words, array $digitWords): array
    {
        if ($digitWords === []) {
            return $words;
        }

        $consumed = array_fill(0, count($digitWords), false);

        $resolved = array_map(function (array $word) use ($digitWords, &$consumed): array {
            $originalDigits = preg_replace('/\D+/', '', (string) $word['text']);

            $bestIndex = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($digitWords as $index => $digitWord) {
                $positionDistance = abs($word['left'] - $digitWord['left'])
                    + abs($word['center_y'] - $digitWord['center_y']);

                if (
                    $positionDistance > 35
                    || abs($word['left'] - $digitWord['left']) > 45
                    || abs($word['center_y'] - $digitWord['center_y']) > 22
                ) {
                    continue;
                }

                $candidateDigits = preg_replace('/\D+/', '', (string) $digitWord['text']);
                if ($candidateDigits === '') {
                    continue;
                }

                $agrees = $originalDigits === ''
                    || (
                        abs(strlen($candidateDigits) - strlen($originalDigits)) <= 1
                        && levenshtein($originalDigits, $candidateDigits) <= 2
                    );

                if (! $agrees) {
                    continue;
                }

                if ($positionDistance < $bestDistance) {
                    $bestIndex = $index;
                    $bestDistance = $positionDistance;
                }
            }

            if ($bestIndex !== null) {
                $consumed[$bestIndex] = true;
                $word['text'] = $digitWords[$bestIndex]['text'];
                $word['confidence'] = max($word['confidence'], $digitWords[$bestIndex]['confidence']);
            }

            return $word;
        }, $words);

        foreach ($digitWords as $index => $digitWord) {
            if (! $consumed[$index]) {
                $resolved[] = $digitWord;
            }
        }

        return $resolved;
    }

    private function dedupeCoordinateWords(array $words): array
    {
        usort(
            $words,
            fn (array $a, array $b) => $a['center_y'] <=> $b['center_y'] ?: $a['left'] <=> $b['left']
        );

        $result = [];

        foreach ($words as $word) {
            $duplicate = false;

            foreach ($result as $index => $existing) {
                if (
                    abs($word['left'] - $existing['left']) <= 25
                    && abs($word['center_y'] - $existing['center_y']) <= 18
                    && $this->normalize((string) $word['text']) === $this->normalize((string) $existing['text'])
                ) {
                    $duplicate = true;

                    if ($word['confidence'] > $existing['confidence']) {
                        $result[$index] = $word;
                    }

                    break;
                }
            }

            if (! $duplicate) {
                $result[] = $word;
            }
        }

        return $result;
    }

    private function detectPhysicalRowCenters(array $words, int $pageHeight): array
    {
        $positions = [];

        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            $y = (float) ($word['center_y'] ?? 0);

            /*
             * A full page's last table row can sit right at the bottom
             * margin. The previous 0.94 cutoff was clipping genuine last
             * rows (dropping real customers), not just footer noise —
             * this function already only keeps positions that look like a
             * date/mobile/name, so widening the margin does not let raw
             * footer text back in.
             */
            if ($text === '' || $y < ($pageHeight * 0.08) || $y > ($pageHeight * 0.975)) {
                continue;
            }

            $isDate = $this->containsDatePattern($text)
                || preg_match('/\d{1,2}[:.]\d{2}/', $text);
            $isMobile = $this->extractMobileValue($text) !== null
                || preg_match('/\d{7,}/', preg_replace('/\D+/', '', $text));

            $isName = strlen(preg_replace('/[^A-Za-z]/', '', $text)) >= 3
                && preg_match('/[A-Z]{2,}/', Str::upper($text));

            if ($isDate || $isMobile || $isName) {
                $positions[] = $y;
            }
        }

        if ($positions === []) {
            return [];
        }

        sort($positions, SORT_NUMERIC);

        $clusters = [];
        foreach ($positions as $position) {
            $lastClusterIndex = count($clusters) - 1;
            $lastPosition = $lastClusterIndex >= 0
                ? $clusters[$lastClusterIndex][count($clusters[$lastClusterIndex]) - 1]
                : null;

            if ($lastPosition === null || ($position - $lastPosition) > 20) {
                $clusters[] = [$position];
            } else {
                $clusters[$lastClusterIndex][] = $position;
            }
        }

        $centers = array_map(
            fn (array $cluster): float => array_sum($cluster) / count($cluster),
            $clusters,
        );

        if (count($centers) < 2) {
            return $centers;
        }

        $pitch = $this->estimateGridPitch($centers);
        if ($pitch <= 0) {
            return $centers;
        }

        $filled = [$centers[0]];

        for ($i = 1, $count = count($centers); $i < $count; $i++) {
            $previous = end($filled);
            $gap = $centers[$i] - $previous;

            if ($gap > ($pitch * 1.45)) {
                $missing = max(1, (int) round($gap / $pitch) - 1);
                $step = $gap / ($missing + 1);

                for ($n = 1; $n <= $missing; $n++) {
                    $filled[] = $previous + ($step * $n);
                }
            }

            $filled[] = $centers[$i];
        }

        return array_values(array_map('floatval', $filled));
    }

    private function estimateGridPitch(array $centers): float
    {
        if (count($centers) < 2) {
            return 0.0;
        }

        $gaps = [];
        for ($i = 1, $count = count($centers); $i < $count; $i++) {
            $gap = (float) $centers[$i] - (float) $centers[$i - 1];

            if ($gap >= 45 && $gap <= 140) {
                $gaps[] = $gap;
            }
        }

        return $gaps !== [] ? $this->median($gaps) : 85.0;
    }

    /**
     * Last-resort row anchors, used only when the column-crop based row
     * detection (detectPhysicalRowCenters on the date/name/mobile/extra
     * crops) finds fewer than two rows — for example a scan where the
     * column crops themselves failed to OCR anything usable. Falls back to
     * full-page word coordinates and anchors a row wherever a date or
     * mobile value is recognised, since those are the most reliably-read
     * tokens on a noisy scan.
     */
    private function buildRowAnchors(
        array $tsvs,
        array $fullWords,
        int $dateBoundary,
        int $mobileBoundary,
        int $pageHeight,
    ): array {
        $anchors = [];

        foreach ($fullWords as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $y = (float) ($word['center_y'] ?? 0);
            if ($y < ($pageHeight * 0.08) || $y > ($pageHeight * 0.975)) {
                continue;
            }

            $left = (int) ($word['left'] ?? 0);
            $isDateAnchor = $left < $dateBoundary && $this->containsDatePattern($text);
            $isMobileAnchor = $left >= $mobileBoundary && $this->extractMobileValue($text) !== null;

            if ($isDateAnchor || $isMobileAnchor) {
                $anchors[] = ['top' => $y];
            }
        }

        usort($anchors, fn (array $a, array $b) => $a['top'] <=> $b['top']);

        return $anchors;
    }

    private function wordsNearRow(array $words, float $rowCenter, float $tolerance): array
    {
        $result = array_values(array_filter(
            $words,
            fn (array $word): bool => abs((float) $word['center_y'] - $rowCenter) <= $tolerance
        ));

        usort($result, fn (array $a, array $b) => $a['left'] <=> $b['left']);

        return $result;
    }

    private function joinWords(array $words): string
    {
        if ($words === []) {
            return '';
        }

        usort($words, fn (array $a, array $b) => $a['left'] <=> $b['left']);

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_map(
            fn (array $word): string => trim((string) $word['text']),
            $words,
        ))));
    }

    private function cleanCoordinateName(array $words): ?string
    {
        if ($words === []) {
            return null;
        }

        usort($words, fn (array $a, array $b) => $a['left'] <=> $b['left']);

        $parts = [];
        foreach ($words as $word) {
            $text = trim((string) $word['text']);

            if ($text === '' || $this->containsDatePattern($text)) {
                continue;
            }

            if ($this->extractMobileValue($text) !== null) {
                continue;
            }

            $text = preg_replace('/[|=:;>]+/', ' ', $text);
            $text = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', (string) $text);
            $text = trim((string) $text);

            if ($text === '') {
                continue;
            }

            $noiseTokens = [
                'UH',
                'TA',
                'HE',
                'HI',
                'II',
                'III',
                'WILDL',
                'MAH',
                'MED',
                'AAA',
                'AA',
                'AE',
                'EEE',
                'I',
                'N',
                'O',
                'EE',
                'OE',
                'VE',
                'ATI',
                'ATi',
                'HIE',
                'PSO',
                'G',
                'RAY',
            ];

            if (in_array(Str::upper($text), $noiseTokens, true)) {
                continue;
            }

            /* The source table prints names in uppercase; lowercase-only
             * fragments are usually OCR debris (for example `nora`, `is`). */
            if (strlen($text) > 2 && ! preg_match('/[A-Z]/', $text)) {
                continue;
            }

            if (
                strlen(preg_replace('/[^A-Za-z]/', '', $text)) < 2
                && ! preg_match('/^[A-Za-z]{2}$/', $text)
            ) {
                continue;
            }

            $parts[] = $text;
        }

        $name = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));

        if ($name === '') {
            return null;
        }

        return $name;
    }

    /**
     * Lightweight cleanup for a trailing free-text coordinate column (e.g.
     * Product Type). Unlike cleanCoordinateName this does not restrict
     * itself to uppercase name-shaped tokens — values like "Personal Loan"
     * are normal here — but it still strips stray date/time fragments that
     * bled in from the neighbouring mobile-number crop on a noisy scan.
     */
    private function cleanCoordinateText(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $text = preg_replace(
            '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}(?:\s+\d{1,2}[:.]\d{2}\s*(?:AM|PM)?)?/i',
            ' ',
            $text
        );
        $text = preg_replace('/\b\d{1,2}[:.]\d{2}\s*(?:AM|PM)\b/i', ' ', (string) $text);
        $text = preg_replace('/[|=:;>]+/', ' ', (string) $text);
        $text = preg_replace('/\s+/', ' ', (string) $text);
        $text = trim((string) $text, " \t\n\r\0\x0B-");

        if ($text === '' || strlen(preg_replace('/[^A-Za-z0-9]/', '', $text)) < 2) {
            return null;
        }

        return $text;
    }

    private function isLikelyHeaderOrGarbageName(string $name): bool
    {
        $normalized = $this->normalize($name);

        if ($normalized === '') {
            return true;
        }

        $headerTokens = [
            'created on',
            'full name entity',
            'full name',
            'entity',
            'mobile number',
        ];

        foreach ($headerTokens as $header) {
            if ($normalized === $this->normalize($header)) {
                return true;
            }
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $name);

        return strlen($letters) < 2;
    }

    private function extractMobileValueFromRightSide(array $words, int $mobileBoundary): ?string
    {
        foreach ($words as $word) {
            if ((int) ($word['left'] ?? 0) < $mobileBoundary) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) ($word['text'] ?? ''));

            if (preg_match('/^[6-9]\d{9}$/', $digits)) {
                return $digits;
            }

            if (strlen($digits) > 10 && preg_match('/[6-9]\d{9}$/', $digits)) {
                return substr($digits, -10);
            }
        }

        return null;
    }

    private function containsDatePattern(string $value): bool
    {
        return (bool) preg_match(
            '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/',
            $value
        );
    }

    private function findMobileLeft(array $words, int $mobileBoundary): ?int
    {
        foreach ($words as $word) {
            if ($word['left'] < $mobileBoundary) {
                continue;
            }

            if ($this->extractMobileValue((string) $word['text']) !== null) {
                return (int) $word['left'];
            }
        }

        return null;
    }

    private function estimateRowHeight(array $rowAnchors): int
    {
        if (count($rowAnchors) < 2) {
            return 85;
        }

        $gaps = [];
        for ($i = 1; $i < count($rowAnchors); $i++) {
            $gap = $rowAnchors[$i]['top'] - $rowAnchors[$i - 1]['top'];
            if ($gap > 20 && $gap < 180) {
                $gaps[] = $gap;
            }
        }

        return $gaps !== [] ? (int) round($this->median($gaps)) : 85;
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? (($values[$middle - 1] + $values[$middle]) / 2)
            : (float) $values[$middle];
    }

    private function extractEnquiryRows(array $lines, array $definitions): array
    {
        if (count($definitions) !== 3) {
            return [];
        }

        $dateField = null;
        $nameField = null;
        $mobileField = null;

        foreach ($definitions as $definition) {
            $key = Str::lower((string) ($definition['key'] ?? ''));
            $type = Str::lower((string) ($definition['type'] ?? 'text'));

            if ($type === 'date' || str_contains($key, 'created')) {
                $dateField = $definition;
            } elseif ($type === 'mobile' || str_contains($key, 'mobile') || str_contains($key, 'contact')) {
                $mobileField = $definition;
            } else {
                $nameField = $definition;
            }
        }

        if (! $dateField || ! $nameField || ! $mobileField) {
            return [];
        }

        $dateKey = (string) ($dateField['key'] ?? '');
        $nameKey = (string) ($nameField['key'] ?? '');
        $mobileKey = (string) ($mobileField['key'] ?? '');

        if ($dateKey === '' || $nameKey === '' || $mobileKey === '') {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || $this->looksLikeAnyHeader($line, $definitions)) {
                continue;
            }

            /*
         * Mobile number is the strongest row anchor.
         */
            if (! preg_match('/(?<!\d)(?:\+?91[\s-]?)?[6-9]\d{9}(?!\d)/', $line, $mobileMatch)) {
                continue;
            }

            $mobile = preg_replace('/\D+/', '', $mobileMatch[0]);

            if (strlen($mobile) > 10) {
                $mobile = substr($mobile, -10);
            }

            if (strlen($mobile) !== 10) {
                continue;
            }

            /*
         * Extract date + time if present.
         */
            $createdOn = null;

            if (preg_match(
                '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}(?:\s+\d{1,2}[:\-]\d{2}\s*(?:AM|PM)?)?/i',
                $line,
                $dateMatch
            )) {
                $createdOn = $this->normalizeValue($dateMatch[0], 'date');
            }

            /*
         * Remove date/time and mobile from the source line.
         * Whatever remains is the customer name.
         */
            $name = $line;

            if ($dateMatch !== []) {
                $name = str_replace($dateMatch[0], ' ', $name);
            }

            $name = str_replace($mobileMatch[0], ' ', $name);

            /*
         * Remove time-only values such as:
         * 01:08 PM
         * 04-40 PM
         */
            $name = preg_replace(
                '/\b\d{1,2}[:\-]\d{2}\s*(?:AM|PM)\b/i',
                ' ',
                $name
            );

            /*
         * Remove OCR separators/punctuation.
         */
            $name = preg_replace('/[|=:;>]+/', ' ', $name);
            $name = preg_replace('/\s+/', ' ', $name);
            $name = trim($name, " \t\n\r\0\x0B-");

            if ($name === '') {
                continue;
            }

            /*
         * Reject obvious OCR garbage.
         */
            if (
                preg_match('/^(?:created|on|full|name|entity|mobile|number)$/i', $name)
                || strlen(preg_replace('/[^A-Za-z]/', '', $name)) < 2
            ) {
                continue;
            }

            $data = [
                $dateKey => $createdOn,
                $nameKey => $name,
                $mobileKey => $mobile,
            ];

            $present = count(array_filter(
                $data,
                fn ($value) => $value !== null && trim((string) $value) !== ''
            ));

            $rows[] = [
                'data' => $data,
                'confidence' => round($present / 3, 4),
                'source_row' => $line,
            ];
        }

        /*
     * Do not activate this parser unless it actually found
     * a meaningful number of rows.
     */
        if (count($rows) < 2) {
            return [];
        }

        return [
            'headers' => array_map(
                fn ($definition) => (string) ($definition['label'] ?? $definition['key'] ?? ''),
                $definitions
            ),
            'rows' => $rows,
        ];
    }

    /**
     * Reconstruct a table when OCR returns complete columns one after another.
     * Example:
     *   Created On
     *   date1
     *   date2
     *   ...
     *   Full Name/Entity
     *   name1
     *   name2
     *   ...
     *   Mobile Number
     *   mobile1
     *   mobile2
     *   ...
     *   Product Type
     *   Personal Loan
     *   Personal Loan
     *   ...
     */
    private function extractColumnarRows(array $lines, array $definitions): array
    {
        if (count($definitions) < 2) {
            return [];
        }

        $headers = [];
        $headerIndexes = [];

        foreach ($definitions as $definitionIndex => $definition) {
            $found = $this->findHeaderIndex($lines, $definition);
            if ($found === null) {
                return [];
            }

            $headerIndexes[$definitionIndex] = $found['index'];
            $headers[$definitionIndex] = trim($lines[$found['index']]);
        }

        // A genuine columnar layout has headers in the same order as the template.
        $orderedIndexes = array_values($headerIndexes);
        if ($orderedIndexes !== array_values(array_unique($orderedIndexes))) {
            return [];
        }

        for ($i = 1, $count = count($orderedIndexes); $i < $count; $i++) {
            if ($orderedIndexes[$i] <= $orderedIndexes[$i - 1]) {
                return [];
            }
        }

        $columns = [];
        foreach ($definitions as $index => $definition) {
            $start = $headerIndexes[$index] + 1;
            $end = $index + 1 < count($definitions)
                ? $headerIndexes[$index + 1]
                : count($lines);

            $values = [];
            for ($lineIndex = $start; $lineIndex < $end; $lineIndex++) {
                $line = trim($lines[$lineIndex]);
                if ($line === '' || $this->looksLikeAnyHeader($line, $definitions)) {
                    continue;
                }

                $value = $this->extractColumnValue($line, $definition);
                if ($value === null || trim($value) === '') {
                    continue;
                }

                $values[] = $value;
            }

            if ($values === []) {
                return [];
            }

            $columns[$index] = $values;
        }

        $rowCount = max(array_map('count', $columns));
        if ($rowCount < 2) {
            return [];
        }

        $rows = [];
        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            $data = [];
            $present = 0;
            $sourceParts = [];

            foreach ($definitions as $definitionIndex => $definition) {
                $key = (string) ($definition['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $value = $columns[$definitionIndex][$rowIndex] ?? null;
                $data[$key] = $value;

                if ($value !== null && trim((string) $value) !== '') {
                    $present++;
                    $sourceParts[] = $value;
                }
            }

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $expected = count(array_filter(
                $definitions,
                fn ($definition) => filled($definition['key'] ?? null)
            ));

            $rows[] = [
                'data' => $data,
                'confidence' => $expected > 0 ? round($present / $expected, 4) : null,
                'source_row' => implode(' | ', $sourceParts),
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function findHeaderIndex(array $lines, array $definition): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($lines as $index => $line) {
            $score = $this->headerMatchScore($line, $definition);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['index' => $index, 'score' => $score];
            }
        }

        return $bestScore >= 0.55 ? $best : null;
    }

    private function headerMatchScore(string $line, array $definition): float
    {
        $lineTokens = $this->tokens($line);
        if ($lineTokens === []) {
            return 0.0;
        }

        $candidates = array_filter([
            $definition['key'] ?? null,
            $definition['label'] ?? null,
            ...(is_array($definition['aliases'] ?? null)
                ? $definition['aliases']
                : (filled($definition['aliases'] ?? null)
                    ? explode(',', (string) $definition['aliases'])
                    : [])),
        ]);

        $best = 0.0;
        foreach ($candidates as $candidate) {
            $candidateTokens = $this->tokens((string) $candidate);
            if ($candidateTokens === []) {
                continue;
            }

            $intersection = count(array_intersect($candidateTokens, $lineTokens));
            $score = $intersection / count($candidateTokens);

            // Strong boost for exact normalized header matches.
            if ($this->normalize($line) === $this->normalize((string) $candidate)) {
                $score = 1.0;
            }

            $best = max($best, $score);
        }

        return $best;
    }

    private function extractColumnValue(string $line, array $definition): ?string
    {
        $type = (string) ($definition['type'] ?? 'text');
        $value = trim($line, " \t\n\r\0\x0B:;|,");

        return match ($type) {
            'date' => $this->extractDateValue($value),
            'mobile' => $this->extractMobileValue($value),
            'pan' => $this->extractPanValue($value),
            'email' => $this->extractEmailValue($value),
            'number', 'decimal' => $this->extractNumberValue($value),
            default => $this->cleanTextValue($value, $definition),
        };
    }

    private function extractDateValue(string $value): ?string
    {
        if (preg_match('/\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}(?:\s+\d{1,2}:\d{2}(?:\s*[AP]M)?)?/i', $value, $match)) {
            return $this->normalizeValue($match[0], 'date');
        }

        return null;
    }

    private function extractMobileValue(string $value): ?string
    {
        if (preg_match('/(?:\+?91[\s-]?)?[6-9]\d[\d\s-]{8,}\b/', $value, $match)) {
            $mobile = preg_replace('/\D+/', '', $match[0]);
            if (strlen($mobile) >= 10) {
                return substr($mobile, -10);
            }
        }

        return null;
    }

    private function extractPanValue(string $value): ?string
    {
        if (preg_match('/[A-Za-z]{5}[\s-]?\d{4}[\s-]?[A-Za-z]/', $value, $match)) {
            return $this->normalizeValue($match[0], 'pan');
        }

        return null;
    }

    private function extractEmailValue(string $value): ?string
    {
        if (preg_match('/[^\s]+@[^\s]+\.[^\s]+/', $value, $match)) {
            return $match[0];
        }

        return null;
    }

    private function extractNumberValue(string $value): ?string
    {
        if (preg_match('/-?[\d,]+(?:\.\d+)?/', $value, $match)) {
            return preg_replace('/[^0-9.\-]/', '', $match[0]);
        }

        return null;
    }

    private function cleanTextValue(string $value, array $definition = []): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        /*
     * 1. Remove OCR spill-over.
     *
     * Handles:
     * RAMPRASADGHOSH => G2HOIBOMBTA | yeaonal Laan
     * RAMPRASADGHOSH = > G2HOIBOMBTA
     * RAMPRASADGHOSH -> something
     * RAMPRASADGHOSH | something
     */
        $value = preg_split(
            '/\s*(?:=+\s*>|->|\|)\s*/i',
            $value,
            2
        )[0] ?? $value;

        /*
     * 2. Remove complete date + time prefix.
     *
     * Example:
     * 19/08/2026 04:40 PM J DINESH KUMAR
     *
     * becomes:
     * J DINESH KUMAR
     */
        $value = preg_replace(
            '/^\s*\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\s+\d{1,2}[:\-]\d{2}\s*(?:AM|PM)?\s*/i',
            '',
            $value
        );

        /*
     * 3. Remove time-only prefix.
     *
     * Handles:
     * 04:40 PM J DINESH KUMAR
     * 04-40 PM J DINESH KUMAR
     * 04:40PM J DINESH KUMAR
     * 04-40PM J DINESH KUMAR
     */
        $value = preg_replace(
            '/^\s*\d{1,2}[:\-]\d{2}\s*(?:AM|PM)\s*/i',
            '',
            $value
        );

        /*
     * 4. Remove OCR punctuation from beginning.
     */
        $value = preg_replace(
            '/^[|=:;>\-]+\s*/',
            '',
            $value
        );

        /*
     * 5. Identify the configured field.
     */
        $fieldKey = Str::lower(
            (string) (
                $definition['key']
                ?? ''
            )
        );

        $fieldLabel = Str::lower(
            (string) (
                $definition['label']
                ?? ''
            )
        );

        /*
     * 6. Customer-name specific cleanup.
     *
     * If OCR has accidentally retained a time anywhere
     * at the beginning of the value, remove it.
     */
        if (
            str_contains($fieldKey, 'customer')
            || str_contains($fieldKey, 'name')
            || str_contains($fieldLabel, 'customer')
            || str_contains($fieldLabel, 'name')
        ) {
            $value = preg_replace(
                '/^\s*\d{1,2}[:\-]\d{2}\s*(?:AM|PM)\s*/i',
                '',
                $value
            );

            /*
         * Remove accidental date prefix again after all cleanup.
         */
            $value = preg_replace(
                '/^\s*\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\s*/',
                '',
                $value
            );
        }

        /*
     * 7. Product Type specific cleanup.
     *
     * Never allow OCR garbage after the actual known
     * product name.
     */
        if (
            str_contains($fieldKey, 'product')
            || str_contains($fieldKey, 'loan_type')
            || str_contains($fieldLabel, 'product')
            || str_contains($fieldLabel, 'loan type')
        ) {
            $productPatterns = [
                'personal loan' => 'Personal Loan',
                'home loan' => 'Home Loan',
                'business loan' => 'Business Loan',
                'loan against property' => 'Loan Against Property',
                'car loan' => 'Car Loan',
                'vehicle loan' => 'Vehicle Loan',
            ];

            foreach ($productPatterns as $pattern => $cleanValue) {
                if (preg_match('/\b'.preg_quote($pattern, '/').'\b/i', $value)) {
                    return $cleanValue;
                }
            }
        }

        /*
     * 8. Remove trailing OCR punctuation.
     */
        $value = preg_replace(
            '/\s*[|=:;]+\s*$/',
            '',
            $value
        );

        /*
     * 9. Normalize whitespace.
     */
        $value = preg_replace(
            '/\s+/',
            ' ',
            $value
        );

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function detectHeaderLine(array $lines, array $definitions): ?int
    {
        $bestIndex = null;
        $bestScore = 0;

        foreach ($lines as $index => $line) {
            $score = 0;
            foreach ($definitions as $definition) {
                if ($this->headerMatchScore($line, $definition) >= 0.55) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= min(2, count($definitions)) ? $bestIndex : null;
    }

    private function parseRow(string $line, array $definitions): array
    {
        $data = [];
        $remaining = $line;

        foreach ($definitions as $position => $definition) {
            $key = (string) ($definition['key'] ?? '');
            $type = (string) ($definition['type'] ?? 'text');
            if ($key === '') {
                continue;
            }

            $isLast = $position === array_key_last($definitions);
            if ($isLast) {
                $value = trim($remaining, " \t\n\r\0\x0B:;|,");
                $remaining = '';
            } else {
                [$value, $remaining] = $this->consumeTypedValue($remaining, $type, $definitions, $position);
            }

            $data[$key] = $this->normalizeValue($value ?? '', $type);
        }

        return $data;
    }

    private function consumeTypedValue(string $remaining, string $type, array $definitions, int $position): array
    {
        $remaining = ltrim($remaining);

        return match ($type) {
            'date' => $this->consumeRegex($remaining, '/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}(?:\s+\d{1,2}:\d{2}(?:\s*[AP]M)?)?/i'),
            'mobile' => $this->consumeRegex($remaining, '/^(?:\+?91[\s-]?)?[6-9]\d[\d\s-]{8,}\b/'),
            'pan' => $this->consumeRegex($remaining, '/^[A-Za-z]{5}[\s-]?\d{4}[\s-]?[A-Za-z]/'),
            'email' => $this->consumeRegex($remaining, '/^[^\s]+@[^\s]+\.[^\s]+/'),
            'number', 'decimal' => $this->consumeRegex($remaining, '/^-?[\d,]+(?:\.\d+)?/'),
            default => $this->consumeTextValue($remaining, $definitions, $position),
        };
    }

    private function consumeRegex(string $value, string $pattern): array
    {
        if (preg_match($pattern, $value, $match)) {
            $raw = trim($match[0]);

            return [$raw, ltrim(substr($value, strlen($match[0])))];
        }

        return [null, $value];
    }

    private function consumeTextValue(string $remaining, array $definitions, int $position): array
    {
        for ($next = $position + 1; $next < count($definitions); $next++) {
            $nextType = (string) ($definitions[$next]['type'] ?? 'text');
            $pattern = match ($nextType) {
                'date' => '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}(?:\s+\d{1,2}:\d{2}(?:\s*[AP]M)?)?/i',
                'mobile' => '/(?:\+?91[\s-]?)?[6-9]\d[\d\s-]{8,}\b/',
                'pan' => '/[A-Za-z]{5}[\s-]?\d{4}[\s-]?[A-Za-z]/',
                'email' => '/[^\s]+@[^\s]+\.[^\s]+/',
                'number', 'decimal' => '/-?[\d,]+(?:\.\d+)?/',
                default => null,
            };

            if ($pattern && preg_match($pattern, $remaining, $match, PREG_OFFSET_CAPTURE)) {
                $offset = $match[0][1];
                if ($offset > 0) {
                    return [trim(substr($remaining, 0, $offset)), ltrim(substr($remaining, $offset))];
                }
            }
        }

        $parts = preg_split('/\s{2,}|\t/', $remaining, 2);
        if (count($parts) === 2) {
            return [trim($parts[0]), ltrim($parts[1])];
        }

        return [$remaining, ''];
    }

    private function looksLikeHeaderLine(string $line, array $definitions): bool
    {
        return $this->detectHeaderLine([$line], $definitions) !== null;
    }

    private function looksLikeAnyHeader(string $line, array $definitions): bool
    {
        foreach ($definitions as $definition) {
            if ($this->headerMatchScore($line, $definition) >= 0.75) {
                return true;
            }
        }

        return false;
    }

    private function tokens(string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(explode(' ', $normalized)));
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/[^a-z0-9]+/i', ' ', Str::lower(trim($value)));

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function normalizeValue(string $value, string $type): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B:;|,");
        if ($value === '') {
            return null;
        }

        return match ($type) {
            'mobile' => substr(preg_replace('/\D+/', '', $value), -10),
            'number', 'decimal' => preg_replace('/[^0-9.\-]/', '', $value),
            'pan' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value)),
            default => $value,
        };
    }

    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
