<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class Settings
{
    public const CACHE_KEY = 'settings.all';

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            return Setting::query()->pluck('value', 'key')->all();
        });
    }

    public function get(string $key, $default = null)
    {
        $value = $this->all()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function set(string $key, $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        Cache::forget(self::CACHE_KEY);
    }
}
