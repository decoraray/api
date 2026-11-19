<?php

namespace App\Services\Production;

use RuntimeException;

/** Why a layout cannot be generated; the message is shown to staff as is. */
class NestingException extends RuntimeException
{
    public static function tooMany(int $pieces): self
    {
        return new self('تعداد قطعه‌ها (' . $pieces . ') از سقف ' . SheetNesting::MAX_PIECES . ' قطعه برای یک نقشه برش بیشتر است.');
    }

    public static function doesNotFit(string $name, int $length, int $width, int $sheetLength, int $sheetWidth): self
    {
        return new self("قطعه «{$name}» ({$length}×{$width} میلی‌متر) در ورق {$sheetLength}×{$sheetWidth} جا نمی‌شود.");
    }
}
