<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Models\TrackedLink;
use App\Services\Dashboard\DashboardReport;
use App\Services\Links\LinkStats;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LinkController extends Controller
{
    private const MESSAGES = [
        'code.required' => 'کد لینک را وارد کنید.',
        'code.regex' => 'کد فقط می‌تواند شامل حروف انگلیسی کوچک، عدد و خط تیره (۲ تا ۴۰ نویسه) باشد.',
        'code.unique' => 'این کد قبلاً استفاده شده است.',
        'channel.required' => 'کانال را انتخاب کنید.',
        'channel.in' => 'کانال معتبر نیست.',
        'campaign.max' => 'نام کمپین حداکثر ۶۰ نویسه است.',
        'target_path.required' => 'مسیر مقصد را وارد کنید.',
        'target_path.regex' => 'مسیر مقصد باید با / شروع شود.',
        'target_path.max' => 'مسیر مقصد حداکثر ۲۵۵ نویسه است.',
    ];

    private const PATH_RULE = 'regex:#^/(?!/)[^\s\\\\]*$#';

    public function index(Request $request, LinkStats $stats)
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $stats->rows($from, $to, $this->money($request))]);
    }

    public function store(Request $request, LinkStats $stats)
    {
        $request->merge(['code' => strtolower(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[a-z0-9-]{2,40}$/', 'unique:tracked_links,code'],
            'channel' => ['required', Rule::in(array_column(LeadSource::cases(), 'value'))],
            'campaign' => ['nullable', 'string', 'max:60'],
            'target_path' => ['required', 'string', 'max:255', self::PATH_RULE],
        ], self::MESSAGES);
        $data['campaign'] = isset($data['campaign']) && trim($data['campaign']) !== '' ? trim($data['campaign']) : null;

        $link = TrackedLink::create($data + ['created_by' => $request->user()->id]);

        return response()->json($this->row($stats, $request, $link), 201);
    }

    public function update(Request $request, TrackedLink $link, LinkStats $stats)
    {
        if ($request->hasAny(['code', 'channel'])) {
            throw ValidationException::withMessages(['code' => ['کد و کانال لینک بعد از ساخت قابل تغییر نیست.']]);
        }
        $data = $request->validate([
            'campaign' => ['nullable', 'string', 'max:60'],
            'target_path' => ['sometimes', 'string', 'max:255', self::PATH_RULE],
            'active' => ['sometimes', 'boolean'],
        ], self::MESSAGES);
        if (array_key_exists('campaign', $data)) {
            $data['campaign'] = $data['campaign'] !== null && trim($data['campaign']) !== '' ? trim($data['campaign']) : null;
        }
        $link->update($data);

        return response()->json($this->row($stats, $request, $link));
    }

    private function row(LinkStats $stats, Request $request, TrackedLink $link): array
    {
        [$from, $to] = $this->period($request);

        return $stats->rows($from, $to, $this->money($request), $link->id)[0];
    }

    private function money(Request $request): bool
    {
        return (bool) $request->user()?->can('prices.view');
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $to = $data['to'] ?? Carbon::now(DashboardReport::TZ)->format('Y-m-d');
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->format('Y-m-d');
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => ['تاریخ شروع باید قبل از تاریخ پایان باشد.']]);
        }

        return [$from, $to];
    }
}
