<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A board thickness parts are cut from (e.g. «بدنه ۱۶ میل»), with the sheet size and waste used for estimates. */
class PanelMaterial extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'thickness_mm', 'sheet_length_mm', 'sheet_width_mm', 'waste_percent', 'active'];

    protected $casts = [
        'thickness_mm' => 'float',
        'sheet_length_mm' => 'integer',
        'sheet_width_mm' => 'integer',
        'waste_percent' => 'integer',
        'active' => 'boolean',
    ];

    public function parts()
    {
        return $this->hasMany(ProductPart::class);
    }
}
