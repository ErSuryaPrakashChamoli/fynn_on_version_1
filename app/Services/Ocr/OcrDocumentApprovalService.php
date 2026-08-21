<?php

namespace App\Services\Ocr;

use App\Models\Customer;
use App\Models\OcrDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OcrDocumentApprovalService
{
    public function __construct(private readonly OcrFieldExtractionService $fieldExtractor)
    {
    }

    public function approve(OcrDocument $document, array $data, int $userId): void
    {
        if ($document->status !== 'completed') {
            throw ValidationException::withMessages([
                'document' => 'Only completed OCR documents can be approved.',
            ]);
        }

        if (! $document->customer_id) {
            throw ValidationException::withMessages([
                'customer' => 'Please map this document to a customer before approval.',
            ]);
        }

        $customer = Customer::find($document->customer_id);

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer' => 'The selected customer no longer exists.',
            ]);
        }

        $allowed = $this->fieldExtractor->allowedCustomerFields();
        $updates = Arr::only($data, $allowed);
        $updates = array_filter($updates, static fn ($value) => $value !== null && $value !== '');

        if ($updates === []) {
            throw ValidationException::withMessages([
                'fields' => 'No customer fields were provided for approval.',
            ]);
        }

        DB::transaction(function () use ($customer, $document, $updates, $userId): void {
            $customer->fill($updates);
            $customer->save();

            $document->update([
                'is_verified' => true,
                'approved_by' => $userId,
                'approved_at' => now(),
                'approved_data' => $updates,
                'rejection_reason' => null,
            ]);
        });
    }

    public function reject(OcrDocument $document, string $reason): void
    {
        if ($document->status !== 'completed') {
            throw ValidationException::withMessages([
                'document' => 'Only completed OCR documents can be rejected.',
            ]);
        }

        $document->update([
            'is_verified' => false,
            'approved_by' => null,
            'approved_at' => null,
            'approved_data' => null,
            'rejection_reason' => trim($reason),
        ]);
    }
}
