<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\TrackedLink;

/** The exact text a campaign sends, its SMS part count, and its tracked link. */
class CampaignMessage
{
    public const OPT_OUT = 'لغو۱۱';

    public static function text(string $body, ?string $linkUrl = null): string
    {
        $text = trim(str_replace("\r\n", "\n", $body));
        if ($linkUrl) {
            $text .= "\n" . $linkUrl;
        }

        return $text . "\n" . self::OPT_OUT;
    }

    public static function forCampaign(Campaign $campaign): string
    {
        return self::text($campaign->body, $campaign->trackedLink?->url());
    }

    /**
     * SMS parts: plain ASCII texts use 160 / 153 characters per part, anything else
     * (Persian) is sent as UCS-2 with 70 / 67. The opt-out line is Persian, so campaign
     * texts are always UCS-2; the ASCII rule only matters for tests and previews.
     */
    public static function parts(string $text): int
    {
        $ascii = !preg_match('/[^\x00-\x7F]/', $text);
        $len = mb_strlen($text);
        [$single, $multi] = $ascii ? [160, 153] : [70, 67];

        return $len <= $single ? 1 : (int) ceil($len / $multi);
    }

    /** Create or update the campaign's tracked link to match its target page; drop it when none. */
    public static function syncLink(Campaign $campaign): void
    {
        if (!$campaign->target_path) {
            if ($campaign->tracked_link_id) {
                $campaign->trackedLink?->update(['active' => false]);
                $campaign->tracked_link_id = null;
                $campaign->save();
            }

            return;
        }

        if ($link = $campaign->trackedLink) {
            $link->update(['target_path' => $campaign->target_path, 'campaign' => $campaign->name, 'active' => true]);

            return;
        }

        $code = 'sms-' . $campaign->id;
        $suffix = 1;
        while (TrackedLink::where('code', $code)->exists()) {
            $code = 'sms-' . $campaign->id . '-' . ++$suffix;
        }

        $link = TrackedLink::create([
            'code' => $code, 'channel' => 'sms', 'campaign' => $campaign->name,
            'target_path' => $campaign->target_path, 'active' => true, 'clicks' => 0, 'created_by' => $campaign->created_by,
        ]);
        $campaign->tracked_link_id = $link->id;
        $campaign->save();
        $campaign->setRelation('trackedLink', $link);
    }
}
