<?php

namespace App\Services\Cibil;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use Illuminate\Support\Str;

/**
 * A stand-in bureau for local development and tests (CIBIL_DRIVER=fake),
 * answering in the same shape as Surepass, for CIBIL and Experian alike. The
 * score is derived from the PAN and the bureau, so the same applicant always
 * gets the same answer from a bureau (and a different one from the other); a PAN starting with
 * "FAIL" (e.g. FAILX1234X) fails, to exercise the error path. A PDF request
 * returns a tiny placeholder PDF inline. AppServiceProvider always binds it
 * for /demo and never binds it for the live app in production.
 */
class FakeCibilClient implements CibilClient
{
    public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult
    {
        if (str_starts_with($applicant->panNumber, 'FAIL')) {
            throw new CibilApiException('The bureau could not find a record for this PAN.');
        }

        $seed = $bureau === CreditBureau::Cibil ? $applicant->panNumber : $applicant->panNumber.'|'.$bureau->value;
        $score = 300 + (crc32($seed) % 601);
        $reference = 'FAKE-'.Str::upper(Str::random(10));

        $data = [
            'source' => "Fake {$bureau->label()} bureau (development only)",
            'client_id' => $reference,
            'name' => $applicant->name,
            'pan' => $applicant->panNumber,
            'gender' => $applicant->gender,
            'credit_score' => (string) $score,
        ];

        return match ($type) {
            CibilRequestType::Score => new CibilFetchResult(score: $score, payload: $data, bureauReference: $reference),
            CibilRequestType::FullReport => new CibilFetchResult(
                score: $score,
                payload: [...$data, 'credit_report' => [[
                    'control_number' => 'FAKE'.crc32($applicant->panNumber),
                    'accounts' => [
                        ['accountType' => 'Personal Loan', 'memberShortName' => 'Sample Bank', 'status' => 'Active', 'amountOverdue' => 0],
                        ['accountType' => 'Credit Card', 'memberShortName' => 'Sample Card Co', 'status' => 'Closed', 'amountOverdue' => 0],
                    ],
                    'enquiries' => [
                        ['enquiryPurpose' => 'Personal Loan', 'memberShortName' => 'Sample Bank'],
                    ],
                ]]],
                bureauReference: $reference,
            ),
            CibilRequestType::Pdf => new CibilFetchResult(
                score: $score,
                payload: $data,
                bureauReference: $reference,
                pdfBase64: base64_encode(self::placeholderPdf($bureau)),
            ),
        };
    }

    private static function placeholderPdf(CreditBureau $bureau): string
    {
        $text = "BT /F1 16 Tf 20 70 Td (Fake {$bureau->label()} report - development only) Tj ET";

        return "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 420 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            .'4 0 obj<</Length '.strlen($text).">>stream\n{$text}\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
