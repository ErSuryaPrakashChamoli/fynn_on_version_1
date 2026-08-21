<?php

namespace App\Services\Ocr;

use App\Models\AiDocumentSchema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Process;

class OcrTableExtractionService
{
    public function extract(string $path, AiDocumentSchema $schema): array
    {
        $definitions = array_values($schema->getFieldDefinitions());

        /*
         * The Enquiry PDF is a scanned multi-page table with exactly:
         * Created On | Full Name/Entity | Mobile Number
         *
         * Do NOT use laravel-ocr's normal PDF extraction for this layout,
         * because the Tesseract PDF driver can process only the first page.
         * Render/OCR every page ourselves and reconstruct rows from the
         * Tesseract TSV coordinates.
         */
        if ($this->isThreeColumnContactTable($definitions)) {
            $multiPage = $this->extractMultiPageTable($path, $definitions);

            if ($multiPage !== []) {
                return $multiPage;
            }
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

        return [
            'headers' => $header ? [$header] : [],
            'rows' => $rows,
            'raw_text' => $rawText,
            'metadata' => is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
        ];
    }

    private function isThreeColumnContactTable(array $definitions): bool
    {
        if (count($definitions) !== 3) {
            return false;
        }

        $hasDate = false;
        $hasName = false;
        $hasMobile = false;

        foreach ($definitions as $definition) {
            $key = Str::lower((string) ($definition['key'] ?? ''));
            $label = Str::lower((string) ($definition['label'] ?? ''));
            $type = Str::lower((string) ($definition['type'] ?? 'text'));

            if ($type === 'date' || str_contains($key, 'created') || str_contains($label, 'created')) {
                $hasDate = true;
                continue;
            }

            if (
                $type === 'mobile'
                || str_contains($key, 'mobile')
                || str_contains($key, 'contact')
                || str_contains($label, 'mobile')
                || str_contains($label, 'contact')
            ) {
                $hasMobile = true;
                continue;
            }

            $hasName = true;
        }

        return $hasDate && $hasName && $hasMobile;
    }

    private function extractMultiPageTable(string $pdfPath, array $definitions): array
    {
        $dateField = null;
        $nameField = null;
        $mobileField = null;

        foreach ($definitions as $definition) {
            $key = Str::lower((string) ($definition['key'] ?? ''));
            $label = Str::lower((string) ($definition['label'] ?? ''));
            $type = Str::lower((string) ($definition['type'] ?? 'text'));

            if ($type === 'date' || str_contains($key, 'created') || str_contains($label, 'created')) {
                $dateField = $definition;
            } elseif (
                $type === 'mobile'
                || str_contains($key, 'mobile')
                || str_contains($key, 'contact')
                || str_contains($label, 'mobile')
                || str_contains($label, 'contact')
            ) {
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

        $imagick = new \Imagick();
        $imagick->setResolution(200, 200);

        try {
            $imagick->pingImage($pdfPath);
            $pageCount = $imagick->getNumberImages();
            $imagick->clear();
            $imagick->destroy();

            if ($pageCount < 1) {
                return [];
            }

            $allRows = [];
            $allRawText = [];

            for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
                $page = new \Imagick();
                $page->setResolution(200, 200);
                $page->readImage($pdfPath . '[' . $pageIndex . ']');
                $page->setIteratorIndex(0);
                $page->setImageFormat('png');
                $page->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                $page->setImageCompressionQuality(95);
                $page->sharpenImage(0, 1);

                $width = $page->getImageWidth();
                $height = $page->getImageHeight();

                $temporaryPath = storage_path(
                    'app/ocr-page-' . uniqid('', true) . '.png'
                );

                $page->writeImage($temporaryPath);

                try {
                    $result = Process::timeout(180)->run([
                        'tesseract',
                        $temporaryPath,
                        'stdout',
                        '--psm',
                        '6',
                        '-l',
                        'eng',
                        'tsv',
                    ]);

                    if ($result->failed()) {
                        throw new \RuntimeException(
                            'Tesseract failed on PDF page ' . ($pageIndex + 1) .
                            ': ' . trim($result->errorOutput())
                        );
                    }

                    $allRawText[] = $result->output();

                    $pageRows = $this->rowsFromTsv(
                        $result->output(),
                        $dateKey,
                        $nameKey,
                        $mobileKey,
                        $width,
                        $height,
                    );

                    foreach ($pageRows as $row) {
                        $allRows[] = $row;
                    }
                } finally {
                    @unlink($temporaryPath);
                    $page->clear();
                    $page->destroy();
                }
            }

            /*
             * A valid extraction must contain multiple rows. If it failed,
             * allow the existing generic OCR pipeline to handle the document.
             */
            if (count($allRows) < 2) {
                return [];
            }

            return [
                'headers' => [
                    (string) ($dateField['label'] ?? $dateKey),
                    (string) ($nameField['label'] ?? $nameKey),
                    (string) ($mobileField['label'] ?? $mobileKey),
                ],
                'rows' => $allRows,
                'raw_text' => implode("\n", $allRawText),
                'metadata' => [
                    'pages_processed' => $pageCount,
                    'rows_extracted' => count($allRows),
                    'engine' => 'tesseract-tsv-multipage',
                ],
            ];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function rowsFromTsv(
        string $tsv,
        string $dateKey,
        string $nameKey,
        string $mobileKey,
        int $pageWidth,
        int $pageHeight,
    ): array {
        $lines = preg_split('/\R/', trim($tsv));

        if (! $lines || count($lines) < 2) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), "\t");
        $indexes = array_flip($header);

        foreach ([
            'left',
            'top',
            'width',
            'height',
            'conf',
            'text',
        ] as $required) {
            if (! array_key_exists($required, $indexes)) {
                return [];
            }
        }

        $words = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, "\t");

            $text = trim((string) ($columns[$indexes['text']] ?? ''));
            if ($text === '') {
                continue;
            }

            $confidence = (float) ($columns[$indexes['conf']] ?? -1);

            /*
             * Ignore extremely poor OCR words. Keep punctuation if it belongs
             * to a usable word/number because the row cleanup handles it.
             */
            if ($confidence >= 0 && $confidence < 20) {
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
                'confidence' => $confidence,
            ];
        }

        if ($words === []) {
            return [];
        }

        /*
         * Tesseract can split one physical row into multiple TSV line_num
         * values. Group by Y position instead of relying on line_num.
         */
        usort($words, function (array $a, array $b): int {
            return $a['top'] <=> $b['top'] ?: $a['left'] <=> $b['left'];
        });

        $yTolerance = max(8, (int) round($pageHeight * 0.008));
        $groups = [];

        foreach ($words as $word) {
            $placed = false;

            foreach ($groups as &$group) {
                $groupTop = $group['top'];

                if (abs($word['top'] - $groupTop) <= $yTolerance) {
                    $group['words'][] = $word;
                    $group['top'] = (int) round(
                        (($groupTop * (count($group['words']) - 1)) + $word['top'])
                        / count($group['words'])
                    );
                    $placed = true;
                    break;
                }
            }
            unset($group);

            if (! $placed) {
                $groups[] = [
                    'top' => $word['top'],
                    'words' => [$word],
                ];
            }
        }

        $rows = [];

        /*
         * These boundaries match the actual A4 table layout:
         *   Date:   left ~ 0-35%
         *   Name:   ~35-73%
         *   Mobile: ~73-100%
         *
         * We still use the 10-digit mobile number as the final row anchor.
         */
        $dateBoundary = (int) round($pageWidth * 0.35);
        $mobileBoundary = (int) round($pageWidth * 0.72);

        foreach ($groups as $group) {
            $groupWords = $group['words'];

            usort(
                $groupWords,
                fn (array $a, array $b) => $a['left'] <=> $b['left']
            );

            $dateParts = [];
            $nameParts = [];
            $mobileParts = [];

            foreach ($groupWords as $word) {
                $text = trim($word['text']);
                $left = $word['left'];

                if ($left >= $mobileBoundary) {
                    $mobileParts[] = $text;
                } elseif ($left >= $dateBoundary) {
                    $nameParts[] = $text;
                } else {
                    $dateParts[] = $text;
                }
            }

            $mobileRaw = implode('', $mobileParts);
            $mobile = preg_replace('/\D+/', '', $mobileRaw);

            /*
             * If the mobile column OCR is slightly misplaced, scan the entire
             * row for a valid 10-digit number.
             */
            if (! preg_match('/^[6-9]\d{9}$/', $mobile)) {
                $rowText = implode(' ', array_column($groupWords, 'text'));

                if (preg_match(
                    '/(?<!\d)(?:\+?91[\s-]?)?[6-9][\d\s-]{8,}\d(?!\d)/',
                    $rowText,
                    $mobileMatch
                )) {
                    $mobile = preg_replace('/\D+/', '', $mobileMatch[0]);

                    if (strlen($mobile) > 10) {
                        $mobile = substr($mobile, -10);
                    }
                }
            }

            if (! preg_match('/^[6-9]\d{9}$/', $mobile)) {
                continue;
            }

            $date = trim(implode(' ', $dateParts));
            $name = trim(implode(' ', $nameParts));

            /*
             * Some OCR output places a date/time fragment in the name column.
             * Remove it before saving.
             */
            $name = preg_replace(
                '/\b\d{1,2}[:\-]\d{2}\s*(?:AM|PM)?\b/i',
                ' ',
                $name
            );

            $name = preg_replace(
                '/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/',
                ' ',
                $name
            );

            $name = preg_replace('/[|=:;>]+/', ' ', $name);
            $name = preg_replace('/\s+/', ' ', $name);
            $name = trim((string) $name, " \t\n\r\0\x0B-");

            /*
             * Header / garbage protection.
             */
            $rowText = Str::lower(
                implode(' ', array_column($groupWords, 'text'))
            );

            if (
                str_contains($rowText, 'created on')
                || str_contains($rowText, 'full name')
                || str_contains($rowText, 'mobile number')
            ) {
                continue;
            }

            if (
                $name === ''
                || strlen(preg_replace('/[^A-Za-z]/', '', $name)) < 2
            ) {
                continue;
            }

            $normalizedDate = $this->normalizeValue($date, 'date');

            $rows[] = [
                'data' => [
                    $dateKey => $normalizedDate,
                    $nameKey => $name,
                    $mobileKey => $mobile,
                ],
                'confidence' => 1.0,
                'source_row' => implode(
                    ' ',
                    array_column($groupWords, 'text')
                ),
            ];
        }

        return $rows;
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
                fn($value) => $value !== null && trim((string) $value) !== ''
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
                fn($definition) => (string) ($definition['label'] ?? $definition['key'] ?? ''),
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
                fn($definition) => filled($definition['key'] ?? null)
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
        if (preg_match('/\d{1,2}[\/-\.]\d{1,2}[\/-\.]\d{2,4}(?:\s+\d{1,2}:\d{2}(?:\s*[AP]M)?)?/i', $value, $match)) {
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
                if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/i', $value)) {
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
