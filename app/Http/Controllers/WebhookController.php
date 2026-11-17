<?php

namespace App\Http\Controllers;

use App\Models\InstagramEvent;
use App\Services\Instagram\InstagramEventProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /** Relay timestamps older or newer than this are rejected (replay protection). */
    private const MAX_SKEW_SECONDS = 300;

    /**
     * Instagram events forwarded by the egress relay (relay/README.md in the root repo).
     * Stores the event once per relay id, answers, then processes it after the response.
     */
    public function instagram(Request $request)
    {
        $key = (string) config('services.instagram.relay_key');
        $id = (string) $request->header('X-Relay-Id');
        $ts = (string) $request->header('X-Relay-Timestamp');
        $body = $request->getContent();

        $valid = $key !== '' && $id !== '' && strlen($id) <= 100 && ctype_digit($ts)
            && abs(time() - (int) $ts) <= self::MAX_SKEW_SECONDS
            && hash_equals('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $key), (string) $request->header('X-Relay-Signature'));
        if (!$valid) {
            return response()->json(['message' => 'invalid signature'], 401);
        }

        $payload = json_decode($body, true);
        $usable = is_array($payload);
        $inserted = DB::table('instagram_events')->insertOrIgnore([
            'relay_id' => $id,
            'kind' => $usable ? InstagramEventProcessor::kindOf($payload) : 'other',
            'payload' => json_encode($usable ? $payload : ['raw' => mb_substr($body, 0, 10000)], JSON_UNESCAPED_UNICODE),
            'status' => $usable ? InstagramEvent::PENDING : InstagramEvent::IGNORED,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted && $usable) {
            $event = InstagramEvent::where('relay_id', $id)->first();
            // Under PHP-FPM this runs after fastcgi_finish_request: the relay is not kept waiting.
            app()->terminating(fn () => app(InstagramEventProcessor::class)->process($event));
        }

        return response()->json(['ok' => true]);
    }
}
