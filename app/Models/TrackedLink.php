<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackedLink extends Model
{
    protected $fillable = ['code', 'channel', 'campaign', 'target_path', 'active', 'clicks', 'created_by'];

    protected $casts = ['active' => 'boolean'];

    /** The active link for a code (case-insensitive), or null. */
    public static function resolve($code): ?self
    {
        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        return static::where('code', strtolower(trim($code)))->where('active', true)->first();
    }

    public function url(): string
    {
        return rtrim((string) config('app.storefront_url'), '/') . '/go/' . $this->code;
    }
}
