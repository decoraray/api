<?php

namespace App\Enums;

enum StockItemKind: string
{
    case MDF_SHEET = 'mdf_sheet';
    case HARDWARE = 'hardware';
    case EDGE_BAND = 'edge_band';
    case PACKAGING = 'packaging';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MDF_SHEET => 'ورق MDF',
            self::HARDWARE => 'یراق',
            self::EDGE_BAND => 'نوار لبه',
            self::PACKAGING => 'بسته‌بندی',
            self::OTHER => 'سایر',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $kind) => ['id' => $kind->value, 'name' => $kind->label()], self::cases());
    }
}
