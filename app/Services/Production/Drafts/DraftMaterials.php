<?php

namespace App\Services\Production\Drafts;

use App\Models\PanelMaterial;

/** The panel materials a draft's parts are cut from: the body, and the thin back (the body when none is set). */
final class DraftMaterials
{
    public function __construct(public readonly PanelMaterial $body, public readonly ?PanelMaterial $back = null)
    {
    }

    public function thickness(): int
    {
        return (int) round($this->body->thickness_mm);
    }

    public function back(): PanelMaterial
    {
        return $this->back ?? $this->body;
    }
}
