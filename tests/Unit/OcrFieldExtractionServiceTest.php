<?php

namespace Tests\Unit;

use App\Services\Ocr\OcrFieldExtractionService;
use Tests\TestCase;

class OcrFieldExtractionServiceTest extends TestCase
{
    public function test_regex_extraction_still_works_without_python_fields(): void
    {
        $service = new OcrFieldExtractionService;

        $fields = $service->extract("Name: Rahul Kumar\nMobile No: 9876543210\nPAN: ABCDE1234F");

        $this->assertSame('Rahul Kumar', $fields['customer_name']['value']);
        $this->assertSame('9876543210', $fields['mobile_no']['value']);
        $this->assertSame('ABCDE1234F', $fields['pan_number']['value']);
    }

    public function test_python_field_takes_precedence_over_regex_line(): void
    {
        $service = new OcrFieldExtractionService;

        // The regex pass would read "Rahul Kumr" (an OCR typo baked into
        // the raw text); the Python layout candidate is the corrected one
        // and must win.
        $fields = $service->extract(
            'Name: Rahul Kumr',
            null,
            ['customer_name' => ['value' => 'Rahul Kumar', 'confidence' => 0.97, 'source' => 'layout']],
        );

        $this->assertSame('Rahul Kumar', $fields['customer_name']['value']);
        $this->assertSame(0.97, $fields['customer_name']['confidence']);
        $this->assertStringStartsWith('ocr_engine:', $fields['customer_name']['source_label']);
    }

    public function test_python_fields_with_unknown_keys_are_ignored(): void
    {
        $service = new OcrFieldExtractionService;

        $fields = $service->extract('', null, [
            'not_a_real_customer_field' => ['value' => 'whatever', 'confidence' => 0.9],
        ]);

        $this->assertArrayNotHasKey('not_a_real_customer_field', $fields);
    }

    public function test_regex_fallback_still_fills_fields_python_did_not_resolve(): void
    {
        $service = new OcrFieldExtractionService;

        $fields = $service->extract(
            "Name: Rahul Kumar\nMobile No: 9876543210",
            null,
            ['customer_name' => ['value' => 'Rahul Kumar', 'confidence' => 0.97]],
        );

        $this->assertSame('Rahul Kumar', $fields['customer_name']['value']);
        $this->assertSame('9876543210', $fields['mobile_no']['value']);
        $this->assertNull($fields['mobile_no']['confidence']);
    }

    public function test_empty_or_null_python_field_value_is_ignored(): void
    {
        $service = new OcrFieldExtractionService;

        $fields = $service->extract(
            'Name: Rahul Kumar',
            null,
            ['customer_name' => ['value' => '', 'confidence' => 0.9]],
        );

        $this->assertSame('Rahul Kumar', $fields['customer_name']['value']);
    }
}
