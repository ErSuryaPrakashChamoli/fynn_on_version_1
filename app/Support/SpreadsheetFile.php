<?php

namespace App\Support;

use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an uploaded Excel (.xlsx) or CSV file as plain
 * strings: the column names from the header row, then one list of cell
 * values per data row, in column order.
 *
 * The header row is the first row with at least two filled cells, so a
 * title line above the table ("ICICI Login Data – Oct") is passed over.
 * Excel dates come back as Y-m-d and whole numbers without ".0", so a mobile
 * number typed as a number reads "9876543210", not "9876543210.0".
 */
class SpreadsheetFile
{
    /** @var list<string> */
    public const EXTENSIONS = ['xlsx', 'csv', 'txt'];

    /** @var list<string> */
    public const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(
        private readonly string $path,
        private readonly string $extension,
    ) {
        if (! in_array($this->extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Upload an Excel (.xlsx) or CSV file. Old .xls files must be saved as .xlsx first.');
        }
    }

    public static function fromUpload(UploadedFile $file): self
    {
        $extension = Str::lower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        return new self((string) $file->getRealPath(), $extension);
    }

    /**
     * The header row's column names, in order. A blank heading becomes
     * "Column 3"; a repeated one gets a counter ("Mobile (2)").
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        foreach ($this->headerAndDataRows() as $row) {
            return $this->columns = $row;
        }

        return $this->columns = [];
    }

    /**
     * Every row under the header, padded to the header's width.
     *
     * @return Generator<int, list<?string>>
     */
    public function dataRows(): Generator
    {
        $isHeader = true;

        foreach ($this->headerAndDataRows() as $row) {
            if ($isHeader) {
                $isHeader = false;
                $this->columns ??= $row;

                continue;
            }

            yield $row;
        }
    }

    /**
     * The header row (named and de-duplicated), then each data row.
     *
     * @return Generator<int, list<?string>>
     */
    private function headerAndDataRows(): Generator
    {
        $reader = $this->openReader();
        $header = null;

        // Filled rows seen before the header is settled: a one-cell title
        // line is passed over, but a one-column sheet still gets its first
        // row as the header once five single-cell rows have gone by.
        $leadingRows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn (mixed $value): ?string => self::stringValue($value), $row->toArray());
                    $filledCount = count(array_filter($values, fn (?string $value): bool => $value !== null));

                    if ($filledCount === 0) {
                        continue;
                    }

                    if ($header !== null) {
                        yield self::fitToHeader($values, $header);

                        continue;
                    }

                    if ($filledCount >= 2) {
                        yield $header = self::columnNames($values);

                        continue;
                    }

                    $leadingRows[] = $values;

                    if (count($leadingRows) >= 5) {
                        $table = self::leadingRowsAsTable($leadingRows);
                        $header = $table[0];
                        $leadingRows = [];

                        yield from $table;
                    }
                }

                // Only the first sheet is read.
                break;
            }

            if ($header === null && $leadingRows !== []) {
                yield from self::leadingRowsAsTable($leadingRows);
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Single-cell leading rows read as a one-column table: the first is the
     * header, the rest are data.
     *
     * @param  list<list<?string>>  $leadingRows
     * @return list<list<?string>>
     */
    private static function leadingRowsAsTable(array $leadingRows): array
    {
        $header = self::columnNames($leadingRows[0]);

        return [
            $header,
            ...array_map(fn (array $values): array => self::fitToHeader($values, $header), array_slice($leadingRows, 1)),
        ];
    }

    /**
     * @param  list<?string>  $values
     * @param  list<string>  $header
     * @return list<?string>
     */
    private static function fitToHeader(array $values, array $header): array
    {
        return array_pad(array_slice($values, 0, count($header)), count($header), null);
    }

    private function openReader(): ReaderInterface
    {
        if ($this->extension === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = $this->guessCsvDelimiter();
            $reader = new CsvReader($options);
        }

        $reader->open($this->path);

        return $reader;
    }

    /**
     * Comma, unless the first line is clearly split by semicolons or tabs
     * (Excel saves "CSV" with semicolons in some locales).
     */
    private function guessCsvDelimiter(): string
    {
        $handle = @fopen($this->path, 'r');
        $firstLine = $handle ? (string) fgets($handle) : '';

        if ($handle) {
            fclose($handle);
        }

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return max($counts) > 0 ? (string) array_key_first($counts) : ',';
    }

    /**
     * @param  list<?string>  $values
     * @return list<string>
     */
    private static function columnNames(array $values): array
    {
        $lastFilled = 0;

        foreach ($values as $index => $value) {
            if ($value !== null) {
                $lastFilled = $index;
            }
        }

        $names = [];
        $seen = [];

        foreach (array_slice($values, 0, $lastFilled + 1) as $index => $value) {
            $name = $value ?? 'Column '.($index + 1);
            $lowerName = Str::lower($name);
            $seen[$lowerName] = ($seen[$lowerName] ?? 0) + 1;

            $names[] = $seen[$lowerName] > 1 ? "{$name} ({$seen[$lowerName]})" : $name;
        }

        return $names;
    }

    private static function stringValue(mixed $value): ?string
    {
        $value = match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof DateInterval => $value->format('%H:%I:%S'),
            is_bool($value) => $value ? 'Yes' : 'No',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_scalar($value) => (string) $value,
            default => null,
        };

        if ($value === null) {
            return null;
        }

        // Strip a UTF-8 BOM and non-breaking spaces Excel leaves behind.
        $value = trim(str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $value));

        return $value === '' ? null : $value;
    }
}
