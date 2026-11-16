<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsOptOut;
use App\Services\Campaigns\Audience;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SmsOptOutController extends Controller
{
    public function index(Request $request)
    {
        $q = SmsOptOut::query()->with('creator')->latest('id');
        if ($search = trim((string) $request->query('search', ''))) {
            $q->whereIn('phone', Phone::variants($search) ?: [$search]);
        }
        $rows = $q->paginate(50);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (SmsOptOut $o) => self::row($o))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (!preg_match(Audience::MOBILE, $phone)) {
            throw ValidationException::withMessages(['phone' => ['شماره موبایل معتبر نیست.']]);
        }

        $created = SmsOptOut::record($phone, 'staff', $request->user()->id, $data['note'] ?? null);
        $row = SmsOptOut::with('creator')->where('phone', $phone)->first();
        activity()->performedOn($row)->causedBy($request->user())->log('SMS opt-out recorded');

        return response()->json(self::row($row), $created ? 201 : 200);
    }

    private static function row(SmsOptOut $o): array
    {
        return [
            'id' => $o->id,
            'phone' => $o->phone,
            'source' => $o->source,
            'source_label' => $o->source === 'reply' ? 'پاسخ «۱۱»' : 'ثبت توسط کارکنان',
            'created_by' => $o->creator ? trim($o->creator->name . ' ' . $o->creator->surname) : null,
            'note' => $o->note,
            'created_at' => $o->created_at,
        ];
    }
}
