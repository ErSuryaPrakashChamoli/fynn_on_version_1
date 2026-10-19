<?php

namespace App\Enums;

/**
 * Where the PDF of a PDF request stands. The provider hands back a signed
 * link (or the file inline); the app saves the file to private storage
 * straight away and serves it only through its own authorised route.
 */
enum CibilPdfStatus: string
{
    /** Saved to private storage; can be opened by anyone allowed to. */
    case Available = 'available';

    /** The provider answered without a link or file. */
    case Missing = 'missing';

    /** The link could not be fetched yet (e.g. a timeout); kept encrypted for one more try. */
    case DownloadFailed = 'download_failed';

    /** The link had expired or was refused; a new PDF request is needed. */
    case Expired = 'expired';

    /** The provider sent something that is not a valid PDF, or one too large. */
    case Invalid = 'invalid';

    /** The link pointed (or redirected) to a host not on the bureau's allowlist; nothing was fetched from it. */
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Missing => 'Not provided',
            self::DownloadFailed => 'Download pending',
            self::Expired => 'Link expired',
            self::Invalid => 'Invalid file',
            self::Blocked => 'Link host not allowed',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::DownloadFailed => 'warning',
            self::Missing, self::Expired, self::Invalid, self::Blocked => 'danger',
        };
    }
}
