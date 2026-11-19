<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A cutting layout («نقشه برش») of a design or a work order: generated from the parts, or an uploaded file. */
class CuttingLayout extends Model
{
    use LogsActivity;

    public const GENERATED = 'generated';
    public const UPLOADED = 'uploaded';

    protected $fillable = [
        'design_id', 'work_order_id', 'name', 'units', 'kind', 'settings', 'result', 'sheets_count', 'used_percent',
        'fingerprint', 'file_disk', 'file_path', 'file_mime', 'file_size', 'file_name', 'note', 'approved_at',
        'approved_by', 'created_by',
    ];

    protected $casts = [
        'units' => 'integer',
        'settings' => 'array',
        'result' => 'array',
        'sheets_count' => 'integer',
        'used_percent' => 'float',
        'file_size' => 'integer',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (CuttingLayout $layout) {
            if ($layout->file_disk && $layout->file_path) {
                Storage::disk($layout->file_disk)->delete($layout->file_path);
            }
        });
    }

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isGenerated(): bool
    {
        return $this->kind === self::GENERATED;
    }

    /** A link to the uploaded file, valid for 10 minutes. */
    public function fileUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        // Relative signature, as for payment receipts: survives scheme/host differences behind the CDN.
        $path = URL::temporarySignedRoute('cutting-layout-file', now()->addMinutes(10), ['layout' => $this->id], false);

        return rtrim(config('app.url'), '/') . $path;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'units', 'kind', 'note', 'sheets_count', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
