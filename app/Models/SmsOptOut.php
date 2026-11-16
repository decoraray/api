<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;

class SmsOptOut extends Model
{
    protected $fillable = ['phone', 'source', 'created_by', 'note'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Record an opt-out for a number (normalised); returns false if it was already recorded. */
    public static function record(string $phone, string $source, ?int $by = null, ?string $note = null): bool
    {
        $phone = Phone::normalize($phone);

        return (bool) static::query()->insertOrIgnore([
            'phone' => $phone, 'source' => $source, 'created_by' => $by, 'note' => $note,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
