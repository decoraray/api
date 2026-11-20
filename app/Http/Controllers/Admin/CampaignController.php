<?php

namespace App\Http\Controllers\Admin;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\Audience;
use App\Services\Campaigns\CampaignFilters;
use App\Services\Campaigns\CampaignMessage;
use App\Services\Campaigns\CampaignResults;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CampaignController extends Controller
{
    private const PATH_RULE = 'regex:#^/(?!/)[^\s\\\\]*$#';

    public const STATUS_LABELS = [
        'draft' => 'پیش‌نویس',
        'queued' => 'در صف ارسال',
        'sending' => 'در حال ارسال',
        'sent' => 'ارسال شد',
        'cancelled' => 'لغو شد',
    ];

    public function index(CampaignResults $results)
    {
        $rows = Campaign::query()->with('creator')->latest('id')->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Campaign $c) => $this->summary($c) + ['counts' => $results->counts($c)])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function show(Request $request, Campaign $campaign, CampaignResults $results)
    {
        return response()->json($this->payload($request, $campaign, $results));
    }

    public function store(Request $request, CampaignResults $results)
    {
        $data = $this->validated($request, true);
        $campaign = Campaign::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
        CampaignMessage::syncLink($campaign);

        return response()->json($this->payload($request, $campaign->fresh(), $results), 201);
    }

    public function update(Request $request, Campaign $campaign, CampaignResults $results)
    {
        $this->assertEditable($campaign);
        $campaign->update($this->validated($request, false));
        CampaignMessage::syncLink($campaign->fresh());

        return response()->json($this->payload($request, $campaign->fresh(), $results));
    }

    /** Audience counts and the exact text for unsaved form values. */
    public function preview(Request $request, Audience $audience)
    {
        $data = $request->validate([
            'filters' => ['nullable', 'array'],
            ...CampaignFilters::rules('filters'),
            'body' => ['nullable', 'string', 'max:1000'],
            'target_path' => ['nullable', 'string', 'max:255', self::PATH_RULE],
            'campaign_id' => ['nullable', 'integer'],
        ]);

        $link = null;
        if (!empty($data['target_path'])) {
            $existing = !empty($data['campaign_id']) ? Campaign::find($data['campaign_id'])?->trackedLink : null;
            $link = $existing?->url() ?? rtrim((string) config('app.storefront_url'), '/') . '/go/sms-' . ($data['campaign_id'] ?? '000');
        }
        $text = CampaignMessage::text((string) ($data['body'] ?? ''), $link);

        return response()->json([
            'audience' => $audience->preview($data['filters'] ?? []),
            'text' => $text,
            'parts' => CampaignMessage::parts($text),
        ]);
    }

    /** Send the full text once to the requesting user's own phone. */
    public function test(Request $request, Campaign $campaign, BulkSmsClientInterface $sms)
    {
        $line = $this->line();
        $phone = Phone::normalize($request->user()->phone);
        if (!preg_match(Audience::MOBILE, $phone)) {
            throw ValidationException::withMessages(['phone' => ['شماره موبایل شما در حساب کاربری معتبر نیست.']]);
        }

        try {
            $sms->sendBulk($line, CampaignMessage::forCampaign($campaign), [$phone]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['sms' => ['ارسال پیامک آزمایشی ناموفق بود: ' . $e->getMessage()]]);
        }

        return response()->json(['sent_to' => Audience::mask($phone)]);
    }

    /** Fix the audience and queue the campaign; the scheduler sends it. */
    public function send(Request $request, Campaign $campaign, Audience $audience, CampaignResults $results)
    {
        $data = $request->validate(['send_at' => ['nullable', 'date']]);
        $this->line();
        $sendAt = !empty($data['send_at']) ? Carbon::parse($data['send_at'])->utc() : now();
        if ($sendAt->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['send_at' => ['زمان ارسال نمی‌تواند در گذشته باشد.']]);
        }

        DB::transaction(function () use ($campaign, $audience, $sendAt) {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            $this->assertEditable($campaign);

            $list = $audience->build($campaign->filters ?? []);
            if ($list->isEmpty()) {
                throw ValidationException::withMessages(['audience' => ['هیچ گیرنده‌ای با این فیلترها پیدا نشد.']]);
            }

            $now = now();
            foreach ($list->chunk(500) as $chunk) {
                CampaignRecipient::insert($chunk->map(fn ($p) => [
                    'campaign_id' => $campaign->id, 'phone' => $p['phone'], 'user_id' => $p['user_id'], 'lead_id' => $p['lead_id'],
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }

            $campaign->update(['status' => 'queued', 'send_at' => $sendAt, 'recipients_count' => $list->count()]);
        });

        return response()->json($this->payload($request, $campaign->fresh(), $results));
    }

    public function cancel(Request $request, Campaign $campaign, CampaignResults $results)
    {
        $updated = Campaign::whereKey($campaign->id)->where('status', 'queued')->update(['status' => 'cancelled']);
        if (!$updated) {
            throw ValidationException::withMessages(['status' => ['فقط کمپین در صف ارسال را می‌توان لغو کرد.']]);
        }
        activity()->performedOn($campaign)->causedBy($request->user())->log('Campaign cancelled');

        return response()->json($this->payload($request, $campaign->fresh(), $results));
    }

    private function line(): string
    {
        $line = (string) config('services.sms_provider.campaign_line');
        if ($line === '') {
            throw ValidationException::withMessages(['line' => ['خط ارسال پیامک تبلیغاتی تنظیم نشده است (SMS_CAMPAIGN_LINE).']]);
        }

        return $line;
    }

    /** Only drafts: once queued or sent, a campaign and its results are kept. */
    public function destroy(Request $request, Campaign $campaign)
    {
        if (!$campaign->isEditable()) {
            throw ValidationException::withMessages(['status' => ['فقط کمپین پیش‌نویس قابل حذف است.']]);
        }

        // Its tracked link was never sent to anyone: switch it off (links keep their history).
        $campaign->trackedLink?->update(['active' => false]);
        activity()->performedOn($campaign)->causedBy($request->user())
            ->withProperties(['name' => $campaign->name])->log('campaign-draft-deleted');
        $campaign->delete();

        return response()->json(['message' => 'کمپین حذف شد.']);
    }

    private function assertEditable(?Campaign $campaign): void
    {
        if (!$campaign || !$campaign->isEditable()) {
            throw ValidationException::withMessages(['status' => ['این کمپین ارسال شده و قابل تغییر نیست.']]);
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'body' => [$req, 'string', 'max:1000'],
            'target_path' => ['sometimes', 'nullable', 'string', 'max:255', self::PATH_RULE],
            'filters' => ['sometimes', 'nullable', 'array'],
            ...CampaignFilters::rules('filters'),
        ]);
        if ($creating || array_key_exists('filters', $data)) {
            $data['filters'] = CampaignFilters::normalize($data['filters'] ?? []);
        }

        return $data;
    }

    private function summary(Campaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'status_label' => self::STATUS_LABELS[$c->status] ?? $c->status,
            'send_at' => $c->send_at,
            'sent_at' => $c->sent_at,
            'recipients_count' => $c->recipients_count,
            'created_by' => $c->creator ? trim($c->creator->name . ' ' . $c->creator->surname) : null,
            'created_at' => $c->created_at,
        ];
    }

    private function payload(Request $request, Campaign $campaign, CampaignResults $results): array
    {
        $campaign->loadMissing(['creator', 'trackedLink']);
        $text = CampaignMessage::forCampaign($campaign);

        return $this->summary($campaign) + [
            'filters' => ($campaign->filters ?? []) + CampaignFilters::DEFAULTS,
            'body' => $campaign->body,
            'target_path' => $campaign->target_path,
            'link' => $campaign->trackedLink ? ['code' => $campaign->trackedLink->code, 'url' => $campaign->trackedLink->url()] : null,
            'text' => $text,
            'parts' => CampaignMessage::parts($text),
            'editable' => $campaign->isEditable(),
            'line_configured' => filled(config('services.sms_provider.campaign_line')),
            'counts' => $results->counts($campaign),
            'results' => $results->for($campaign, (bool) $request->user()?->can('finance.view')),
        ];
    }
}
