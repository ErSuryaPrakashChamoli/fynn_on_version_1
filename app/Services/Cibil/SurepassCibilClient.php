<?php

namespace App\Services\Cibil;

use App\Enums\CibilFailureCategory;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Support\CibilMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The live bureaus: Surepass's CIBIL and Experian APIs (score, full report,
 * PDF — whichever each bureau has an endpoint for), bound when
 * CIBIL_DRIVER=surepass. Each bureau uses only its own base URL, endpoints
 * and token; one bureau's token is never sent to the other. Every call is
 * billable, so:
 *
 *  - the configuration is checked before anything is sent;
 *  - nothing is retried here;
 *  - a request only counts as answered when the HTTP status, the success
 *    flag and the request type's own data all check out;
 *  - anything that may have been processed without a usable answer — a
 *    timeout after sending, a 500/502/504, an unreadable 2xx — is raised as
 *    "outcome uncertain" rather than as a plain failure;
 *  - the token, the request body, the response and the signed PDF link are
 *    never logged (the call is hidden from Telescope/Debugbar too), and the
 *    provider's own message is scrubbed of identifiers before it is kept.
 */
class SurepassCibilClient implements CibilClient
{
    /** cURL errors raised before the request left this server — nothing can have been processed. */
    private const array NOT_SENT_CURL_ERRORS = [5, 6, 7, 35, 51, 58, 60, 77];

    /** Statuses after which the provider may still have processed (and billed) the request. */
    private const array UNCERTAIN_STATUSES = [500, 502, 504];

    /**
     * @param  array<string, array{base_url?: string|null, token?: string|null, endpoints?: array<string, string|null>, connect_timeout?: int, timeout?: int}>  $bureaus  provider settings keyed by CreditBureau value
     */
    public function __construct(private readonly array $bureaus) {}

    /** Built from config/cibil.php: each bureau's own block. */
    public static function fromConfig(): self
    {
        return new self(collect(CreditBureau::cases())
            ->mapWithKeys(fn (CreditBureau $bureau): array => [$bureau->value => $bureau->config()])
            ->all());
    }

    public function fetch(CibilApplicant $applicant, CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): CibilFetchResult
    {
        $config = $this->bureaus[$bureau->value] ?? [];
        $token = trim((string) ($config['token'] ?? ''));
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $endpoint = (string) ($config['endpoints'][$type->value] ?? '');

        if ($token === '') {
            throw CibilApiException::credentialsMissing($bureau->label());
        }

        if (! str_starts_with(strtolower($baseUrl), 'https://') || ! str_starts_with($endpoint, '/')) {
            throw CibilApiException::notConfigured($bureau->label());
        }

        try {
            $response = CibilHttp::quietly(fn (): Response => Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout((int) ($config['connect_timeout'] ?? 10))
                ->timeout((int) ($config['timeout'] ?? 60))
                ->post($endpoint, self::body($applicant, $bureau)));
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, $bureau);
        }

        return $this->parse($response, $type, $bureau);
    }

    /**
     * Exactly the fields each bureau's API takes: CIBIL also matches on
     * gender, Experian does not take it.
     *
     * @return array<string, string|null>
     */
    private static function body(CibilApplicant $applicant, CreditBureau $bureau): array
    {
        return [
            'pan' => $applicant->panNumber,
            'mobile' => $applicant->mobileNo,
            'name' => $applicant->name,
            ...($bureau === CreditBureau::Cibil ? ['gender' => $applicant->gender] : []),
            'consent' => 'Y',
        ];
    }

    private function parse(Response $response, CibilRequestType $type, CreditBureau $bureau): CibilFetchResult
    {
        $status = $response->status();
        $body = $this->decode($response);
        $messageCode = self::text($body['message_code'] ?? null);
        $providerMessage = CibilMask::scrub(self::text($body['message'] ?? null));

        if (! $response->successful()) {
            throw match (true) {
                $status === 401 => CibilApiException::invalidCredentials($status, $bureau->label()),
                $status === 403 => CibilApiException::notPermitted($status, $bureau->label()),
                $status === 429 => CibilApiException::rateLimited($status),
                in_array($status, self::UNCERTAIN_STATUSES, true) => CibilApiException::uncertain($status, $messageCode, CibilFailureCategory::ProviderError),
                $status >= 500 => CibilApiException::unavailable($status, $bureau->label()),
                default => CibilApiException::rejected($status, $providerMessage, $messageCode),
            };
        }

        // A 2xx that cannot be read may still have been processed and billed.
        if ($body === null) {
            throw CibilApiException::uncertain($status, category: CibilFailureCategory::MalformedResponse);
        }

        if (($body['success'] ?? null) === false) {
            throw CibilApiException::rejected($status, $providerMessage, $messageCode);
        }

        if (($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null)) {
            throw CibilApiException::uncertain($status, $messageCode, CibilFailureCategory::MalformedResponse);
        }

        $data = $body['data'];
        $score = self::score($data['credit_score'] ?? null);
        $reference = self::text($data['client_id'] ?? null);

        if ($type === CibilRequestType::FullReport) {
            $reports = $data['credit_report'] ?? null;

            // One report object or a list of them; empty or missing means no report.
            $reports = is_array($reports) && ! array_is_list($reports) ? [$reports] : $reports;
            $reports = is_array($reports) ? array_values(array_filter($reports, 'is_array')) : [];

            if ($reports === []) {
                throw CibilApiException::missingReport($status, $messageCode);
            }

            $data['credit_report'] = $reports;
        }

        $pdfLink = null;
        $pdfBase64 = null;

        if ($type === CibilRequestType::Pdf) {
            $pdfLink = self::text($data['credit_report_link'] ?? null);
            $pdfBase64 = self::text($data['credit_report_base64'] ?? null);

            if ($pdfLink === null && $pdfBase64 === null) {
                throw CibilApiException::missingPdf($status, $messageCode);
            }
        }

        // The signed link and the file never go into the stored answer.
        unset($data['credit_report_link'], $data['credit_report_base64']);

        return new CibilFetchResult(
            score: $score,
            payload: $data,
            bureauReference: $reference,
            pdfLink: $pdfLink,
            pdfBase64: $pdfBase64,
            providerStatusCode: $status,
            providerMessageCode: $messageCode,
        );
    }

    /**
     * The response as an array, or null when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * A failure before any response came back. If the request never left
     * this server nothing was processed; otherwise (a timeout while waiting,
     * a dropped connection) the provider may have processed it.
     */
    private function connectionFailure(ConnectionException $exception, CreditBureau $bureau): CibilApiException
    {
        $message = $exception->getMessage();
        $errno = preg_match('/cURL error (\d+)/', $message, $matches) ? (int) $matches[1] : null;

        $neverSent = in_array($errno, self::NOT_SENT_CURL_ERRORS, true)
            || ($errno === 28 && preg_match('/(Connection|Resolving) timed out/i', $message) === 1);

        if ($neverSent) {
            return CibilApiException::unavailable(bureau: $bureau->label());
        }

        return CibilApiException::uncertain(category: $errno === 28 ? CibilFailureCategory::Timeout : CibilFailureCategory::Network);
    }

    /**
     * The bureau's score as a number: CIBIL and Experian India scores both
     * run 300–900, with -1/0 standing for "no history". Anything else is
     * treated as no score.
     */
    private static function score(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,3}$/', $value) === 1)) {
            $score = (int) $value;

            return $score >= -1 && $score <= 900 ? $score : null;
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
