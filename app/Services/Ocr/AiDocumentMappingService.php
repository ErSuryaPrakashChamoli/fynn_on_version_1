<?php

namespace App\Services\Ocr;

use App\Models\AiCustomerRecord;
use App\Models\OcrDocument;
use Illuminate\Support\Str;

class AiDocumentMappingService
{
    public function mapAndSave(OcrDocument $document, array $extractedFields, ?float $documentConfidence = null): ?AiCustomerRecord
    {
        $rows = $this->mapRows($document, [[
            'data' => $extractedFields,
            'confidence' => $documentConfidence,
        ]]);

        return $rows[0] ?? null;
    }

    /**
     * Map and persist multiple table rows from one OCR document.
     * Existing rows for the document are removed so re-processing is idempotent.
     */
    public function mapAndSaveRows(OcrDocument $document, array $rows): array
    {
        if (! $document->schema_id || ! $document->schema) {
            return [];
        }

        $document->aiCustomerRecords()->delete();

        return $this->mapRows($document, $rows);
    }

    private function mapRows(OcrDocument $document, array $rows): array
    {
        $schema = $document->schema;
        if (! $schema) {
            return [];
        }

        $saved = [];

        foreach ($rows as $row) {
            $sourceFields = is_array($row['data'] ?? null) ? $row['data'] : [];
            $data = [];
            $confidences = [];

            foreach ($schema->getFieldDefinitions() as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                // Table extraction already returns values keyed by the selected template.
                // Keep that mapping directly instead of trying to interpret scalar row values
                // as legacy OCR field objects.
                if (array_key_exists($key, $sourceFields) && ! is_array($sourceFields[$key])) {
                    // $data[$key] = $sourceFields[$key];
                    $data[$key] = $this->cleanMappedValue(
                        (string) $sourceFields[$key],
                        $field
                    );
                    if (is_numeric($row['confidence'] ?? null)) {
                        $confidences[] = (float) $row['confidence'];
                    }
                    continue;
                }

                $match = $this->findMatch($field, $sourceFields);
                $data[$key] = $match['value'] ?? null;

                if (is_numeric($match['confidence'] ?? null)) {
                    $confidences[] = (float) $match['confidence'];
                }
            }

            $confidence = $confidences !== []
                ? array_sum($confidences) / count($confidences)
                : (is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null);


            $mobile = $data['mobile_number'] ?? null;

            $duplicateOf = null;

            if (filled($mobile)) {
                $normalizedMobile = preg_replace('/\D+/', '', (string) $mobile);

                if (strlen($normalizedMobile) >= 10) {
                    $duplicateOf = AiCustomerRecord::query()
                        ->where('schema_id', $schema->id)
                        ->where('is_duplicate', false)
                        ->get()
                        ->first(function (AiCustomerRecord $existing) use ($normalizedMobile) {
                            $existingMobile = preg_replace(
                                '/\D+/',
                                '',
                                (string) data_get($existing->data, 'mobile_number')
                            );

                            return $existingMobile === $normalizedMobile;
                        });
                }
            }

            $saved[] = AiCustomerRecord::create([
                'schema_id' => $schema->id,
                'ocr_document_id' => $document->id,
                'customer_id' => $document->customer_id,
                'data' => $data,
                'status' => 'review',
                'confidence_score' => $confidence,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,

                'is_duplicate' => $duplicateOf !== null,
                'duplicate_of_id' => $duplicateOf?->id,
            ]);
        }

        return $saved;
    }

    private function findMatch(array $definition, array $extractedFields): ?array
    {
        $candidates = array_filter([
            $definition['key'] ?? null,
            $definition['label'] ?? null,
            ...(is_array($definition['aliases'] ?? null)
                ? $definition['aliases']
                : (filled($definition['aliases'] ?? null)
                    ? explode(',', (string) $definition['aliases'])
                    : [])),
        ], fn($value) => is_string($value) && trim($value) !== '');

        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $candidateNormalized = $this->normalize((string) $candidate);

            foreach ($extractedFields as $sourceKey => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $sourceLabel = $field['source_label'] ?? $sourceKey;

                $sourceCandidates = [
                    (string) $sourceKey,
                    (string) $sourceLabel,
                ];

                foreach ($sourceCandidates as $sourceCandidate) {
                    $sourceNormalized = $this->normalize($sourceCandidate);

                    // Exact match = strongest.
                    if ($candidateNormalized === $sourceNormalized) {
                        return $field;
                    }

                    $score = $this->calculateMappingScore(
                        $candidateNormalized,
                        $sourceNormalized
                    );

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $field;
                    }
                }
            }
        }

        // Only accept a reasonably strong fuzzy match.
        return $bestScore >= 0.75 ? $bestMatch : null;
    }

    private function calculateMappingScore(
        string $candidate,
        string $source
    ): float {
        if ($candidate === '' || $source === '') {
            return 0;
        }

        if ($candidate === $source) {
            return 1.0;
        }

        $candidateWords = array_values(
            array_filter(explode('_', $candidate))
        );

        $sourceWords = array_values(
            array_filter(explode('_', $source))
        );

        if ($candidateWords === [] || $sourceWords === []) {
            return 0;
        }

        $intersection = count(
            array_intersect($candidateWords, $sourceWords)
        );

        $union = count(
            array_unique(array_merge($candidateWords, $sourceWords))
        );

        if ($union === 0) {
            return 0;
        }

        return $intersection / $union;
    }

    private function normalize(string $value): string
    {
        return Str::snake(Str::lower(trim($value)));
    }

    private function cleanMappedValue(string $value, array $field): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Remove leading time:
        // 04-40 PM J DINESH KUMAR
        // 04:40 PM J DINESH KUMAR
        $value = preg_replace(
            '/^\s*\d{1,2}[-:]\d{2}\s*(?:AM|PM)\s*/i',
            '',
            $value
        );

        // Remove leading date + time.
        $value = preg_replace(
            '/^\s*\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\s+\d{1,2}[-:]\d{2}\s*(?:AM|PM)?\s*/i',
            '',
            $value
        );

        // Remove OCR spill-over.
        $value = preg_split(
            '/\s*(?:=+\s*>|->|\|)\s*/i',
            $value,
            2
        )[0] ?? $value;

        $key = Str::lower((string) ($field['key'] ?? ''));
        $label = Str::lower((string) ($field['label'] ?? ''));

        // Normalize Product Type.
        if (
            str_contains($key, 'product') ||
            str_contains($key, 'loan_type') ||
            str_contains($label, 'product') ||
            str_contains($label, 'loan type')
        ) {
            // $products = [
            //     'personal loan' => 'Personal Loan',
            //     'home loan' => 'Home Loan',
            //     'business loan' => 'Business Loan',
            //     'loan against property' => 'Loan Against Property',
            //     'car loan' => 'Car Loan',
            //     'vehicle loan' => 'Vehicle Loan',
            // ];

            // foreach ($products as $search => $replacement) {
            //     if (preg_match('/\b' . preg_quote($search, '/') . '\b/i', $value)) {
            //         return $replacement;
            //     }
            // }

            $value = trim($value);

            // Remove common OCR garbage after product name.
            $value = preg_replace(
                '/\s*[.;,:|]+\s*.*$/',
                '',
                $value
            );

            $product = Str::lower(trim($value));

            $productVariants = [
                // Personal Loan
                'personal loan' => 'Personal Loan',
                'personal lest' => 'Personal Loan',
                'personal lean' => 'Personal Loan',
                'personal lo an' => 'Personal Loan',
                'personal 1oan' => 'Personal Loan',
                'personal loan a' => 'Personal Loan',

                // Home Loan
                'home loan' => 'Home Loan',
                'home lean' => 'Home Loan',

                // Business Loan
                'business loan' => 'Business Loan',
                'business lean' => 'Business Loan',

                // Loan Against Property
                'loan against property' => 'Loan Against Property',

                // Car / Vehicle Loan
                'car loan' => 'Car Loan',
                'vehicle loan' => 'Vehicle Loan',
            ];

            if (isset($productVariants[$product])) {
                return $productVariants[$product];
            }

            // Exact phrase detection as a final fallback.
            foreach ($productVariants as $search => $replacement) {
                if (str_contains($product, $search)) {
                    return $replacement;
                }
            }
        }

        return trim(
            preg_replace('/\s+/', ' ', $value)
        ) ?: null;
    }
}
