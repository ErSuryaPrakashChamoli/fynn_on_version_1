<?php

namespace App\Services\Cibil;

use Closure;
use Laravel\Telescope\Telescope;

/**
 * Runs a call to the bureau (or to its signed PDF link) without the debug
 * tools recording it. Telescope and Debugbar both capture outgoing HTTP
 * requests with their headers and bodies — the bearer token, the
 * applicant's PAN and mobile, the whole credit report and the signed PDF
 * link — so those calls are kept out of them. Debugbar (a dev dependency)
 * is switched off for the rest of the request, since it only stores its
 * data when the response goes out.
 */
final class CibilHttp
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function quietly(Closure $callback): mixed
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }

        return class_exists(Telescope::class)
            ? Telescope::withoutRecording($callback)
            : $callback();
    }
}
