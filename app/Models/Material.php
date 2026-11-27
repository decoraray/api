<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Material extends Model
{
    use HasFactory;
    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // The sheets are not fillable (they need bom.manage), so they are logged by name.
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'description', 'active', 'body_sheet_item_id', 'front_sheet_item_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function bodySheet()
    {
        return $this->belongsTo(StockItem::class, 'body_sheet_item_id');
    }

    public function frontSheet()
    {
        return $this->belongsTo(StockItem::class, 'front_sheet_item_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }
}
