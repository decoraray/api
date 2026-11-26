<?php

namespace App\Services\Production\Drafts;

use App\Models\StockItem;

/** The MDF sheet items a draft's parts are cut from: the body, and the thin back (the body when none is set). */
final class DraftMaterials
{
    public function __construct(public readonly StockItem $body, public readonly ?StockItem $back = null)
    {
    }

    public function thickness(): int
    {
        return (int) round($this->body->thickness_mm);
    }

    public function back(): StockItem
    {
        return $this->back ?? $this->body;
    }
}
