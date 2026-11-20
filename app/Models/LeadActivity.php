<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'user_id', 'type', 'body', 'data', 'edited_at'];

    /** Types staff write by hand; only these can be edited or deleted. */
    public const WRITTEN = ['note', 'call', 'message'];

    protected $casts = ['data' => 'array', 'edited_at' => 'datetime'];

    /** A note/call/message typed by a staff member (not a system or channel entry). */
    public function isWritten(): bool
    {
        return in_array($this->type, self::WRITTEN, true) && $this->user_id !== null;
    }

    /** The author may change it; a SuperAdmin may change any written entry. */
    public function editableBy(?User $user): bool
    {
        return $user !== null && $this->isWritten()
            && ($this->user_id === $user->id || $user->hasRole('SuperAdmin'));
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
