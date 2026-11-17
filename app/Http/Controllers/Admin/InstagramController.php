<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramEvent;
use App\Models\User;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramEventProcessor;
use App\Services\Instagram\InstagramRejected;
use App\Services\Instagram\InstagramSettings;
use App\Services\Instagram\InstagramUnavailable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Instagram connection, toggles and health (settings.manage). The token is write-only. */
class InstagramController extends Controller
{
    /** A long-lived Instagram token lasts 60 days; /me does not report the expiry. */
    private const TOKEN_LIFETIME = 60 * 86400;

    private const KEYS = [
        'enabled' => 'instagram_enabled',
        'comment_replies' => 'instagram_comment_replies',
        'away_enabled' => 'instagram_away_enabled',
        'away_text' => 'instagram_away_text',
        'away_days' => 'instagram_away_days',
        'cooldown_hours' => 'instagram_cooldown_hours',
        'assignee' => 'instagram_assignee',
    ];

    public function show(InstagramSettings $settings)
    {
        return response()->json($this->payload($settings));
    }

    public function update(Request $request, InstagramSettings $settings, InstagramClient $client)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'comment_replies' => ['sometimes', 'boolean'],
            'away_enabled' => ['sometimes', 'boolean'],
            'away_text' => ['sometimes', 'nullable', 'string', 'max:900'],
            'away_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'cooldown_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'assignee' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'token' => ['sometimes', 'nullable', 'string', 'min:20', 'max:1000'],
        ], [
            'away_text.max' => 'متن پاسخ خودکار حداکثر ۹۰۰ نویسه است.',
            'away_days.*' => 'دوره پیام خوش‌آمد باید بین ۱ تا ۹۰ روز باشد.',
            'cooldown_hours.*' => 'فاصله پاسخ به هر نفر باید بین ۰ تا ۷۲۰ ساعت باشد.',
            'assignee.exists' => 'کاربر انتخاب‌شده وجود ندارد.',
            'token.min' => 'توکن اینستاگرام کوتاه است.',
            'token.max' => 'توکن اینستاگرام بیش از حد طولانی است.',
        ]);

        if (!empty($data['assignee']) && !User::find($data['assignee'])?->can('leads.manage')) {
            throw ValidationException::withMessages(['assignee' => ['این کاربر دسترسی مدیریت سرنخ‌ها را ندارد.']]);
        }

        $token = trim((string) ($data['token'] ?? ''));
        if ($token !== '') {
            try {
                $me = $client->me($token);
            } catch (InstagramRejected $e) {
                throw ValidationException::withMessages(['token' => ['توکن اینستاگرام معتبر نیست: ' . $e->getMessage()]]);
            } catch (InstagramUnavailable) {
                throw ValidationException::withMessages(['token' => ['ارتباط با سرور واسط اینستاگرام برقرار نشد. کمی بعد دوباره تلاش کنید.']]);
            }
            $accountId = (string) ($me['user_id'] ?? $me['id'] ?? '');
            if ($accountId === '') {
                throw ValidationException::withMessages(['token' => ['پاسخ اینستاگرام شناسه حساب نداشت.']]);
            }
            $settings->storeToken($token, self::TOKEN_LIFETIME);
            $settings->storeAccount($accountId, $me['username'] ?? null);
            $this->audit($request, 'instagram_token', '***', '*** (' . ($me['username'] ?? $accountId) . ')');
        }

        foreach (self::KEYS as $field => $key) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $value = is_bool($value) ? ($value ? '1' : '0') : (is_string($value) ? (trim($value) ?: null) : $value);
            $old = $settings->raw($key);
            if ((string) $old === (string) $value) {
                continue;
            }
            $settings->set($key, $value);
            $this->audit($request, $key, $old, $value);
        }

        return response()->json($this->payload($settings));
    }

    public function retry(InstagramEvent $event, InstagramEventProcessor $processor)
    {
        if ($event->status === InstagramEvent::PROCESSED) {
            return response()->json(['id' => $event->id, 'status' => $event->status]);
        }
        $event->update(['status' => InstagramEvent::FAILED]);
        $status = $processor->process($event->fresh());

        return response()->json(['id' => $event->id, 'status' => $status, 'error' => $event->fresh()->last_error]);
    }

    private function audit(Request $request, string $key, $old, $new): void
    {
        activity('settings')
            ->causedBy($request->user())
            ->withProperties(['key' => $key, 'old' => $old, 'new' => $new])
            ->log("Setting {$key} changed");
    }

    private function payload(InstagramSettings $s): array
    {
        $failed = InstagramEvent::where('status', InstagramEvent::FAILED);

        return [
            'enabled' => $s->enabled(),
            'comment_replies' => $s->commentReplies(),
            'away_enabled' => $s->awayEnabled(),
            'away_text' => $s->awayText(),
            'away_days' => $s->awayDays(),
            'cooldown_hours' => $s->cooldownHours(),
            'assignee' => $s->assignee(),
            'relay_configured' => filled(config('services.instagram.relay_url')) && filled(config('services.instagram.relay_key')),
            'connection' => [
                'connected' => $s->hasToken() && filled($s->accountId()),
                'account_id' => $s->accountId(),
                'username' => $s->username(),
                'token_expires_at' => $s->tokenExpiresAt()?->toIso8601String(),
                'token_obtained_at' => $s->tokenIssuedAt()?->toIso8601String(),
                'refresh_checked_at' => $s->refreshCheckedAt()?->toIso8601String(),
                'refresh_error' => $s->refreshError(),
            ],
            'health' => [
                'last_event_at' => InstagramEvent::max('created_at'),
                'failed_count' => (clone $failed)->count(),
                'failed' => (clone $failed)->orderByDesc('id')->limit(20)
                    ->get(['id', 'kind', 'attempts', 'last_error', 'created_at', 'updated_at'])
                    ->map(fn ($e) => [
                        'id' => $e->id, 'kind' => $e->kind, 'attempts' => $e->attempts,
                        'error' => $e->last_error, 'created_at' => $e->created_at, 'updated_at' => $e->updated_at,
                    ])->values(),
            ],
        ];
    }
}
