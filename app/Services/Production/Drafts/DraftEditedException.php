<?php

namespace App\Services\Production\Drafts;

use RuntimeException;

/** Regenerating would replace parts or BOM lines someone changed by hand; the caller must confirm. */
class DraftEditedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('قطعات یا فرمول ساخت این طرح پس از ساخت خودکار دستی تغییر کرده است و با ساخت دوباره از بین می‌رود.');
    }
}
