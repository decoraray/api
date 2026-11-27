<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named way of building a product («طرح ساخت»): its own cutting list, BOM and adders.
 * A product can have any number of designs; one is the product's default and a variation can have its own default.
 */
class ProductDesign extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_id', 'name', 'dimensions', 'note', 'is_default', 'active',
        'source_work_order_id', 'created_by', 'approved_at', 'approved_by',
        'draft_template', 'draft_params', 'draft_hash',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'approved_at' => 'datetime',
        'draft_params' => 'array',
    ];

    protected $attributes = [
        'is_default' => false,
        'active' => true,
    ];

    /** The «تأیید شده» badge: informational only. */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Generated from a template («پیش‌نویس خودکار») and not approved yet. */
    public function isDraft(): bool
    {
        return $this->draft_template !== null && $this->approved_at === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'dimensions', 'note', 'is_default', 'active', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Variations that name this design as theirs. */
    public function variations()
    {
        return $this->hasMany(Variation::class, 'design_id')->orderBy('id');
    }

    /**
     * Every variation built from this design: those naming it and, for the product's default design, those naming
     * none.
     */
    public function builtVariations()
    {
        return Variation::where('product_id', $this->product_id)
            ->where(function ($q) {
                $q->where('design_id', $this->id);
                if ($this->is_default) {
                    $q->orWhereNull('design_id');
                }
            })
            ->orderBy('id');
    }

    public function layouts()
    {
        return $this->hasMany(CuttingLayout::class, 'design_id');
    }

    public function parts()
    {
        return $this->hasMany(ProductPart::class, 'design_id')->orderBy('position')->orderBy('id');
    }

    public function bomLines()
    {
        return $this->hasMany(BomLine::class, 'design_id')->orderBy('id');
    }

    /** Service adders one unit needs (vacuum, colour…); stock-linked adders live on the BOM. */
    public function adders()
    {
        return $this->hasMany(DesignAdder::class, 'design_id')->orderBy('id');
    }

    public function sourceWorkOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'source_work_order_id');
    }
}
