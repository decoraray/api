<?php

namespace App\Console\Commands;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Models\SmsOptOut;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Turns replies of «11» / «لغو» to the campaign line into permanent opt-outs. */
class SyncSmsOptOuts extends Command
{
    public const LAST_ID_KEY = 'sms_last_receive_id';

    protected $signature = 'campaigns:sync-opt-outs';

    protected $description = 'Record SMS opt-out replies received on the campaign line';

    public function handle(BulkSmsClientInterface $sms, Settings $settings): int
    {
        $line = (string) config('services.sms_provider.campaign_line');
        if ($line === '') {
            return self::SUCCESS;
        }

        try {
            $messages = $sms->receiveLatest($line, 100);
        } catch (Throwable $e) {
            Log::warning('Opt-out sync failed', ['error' => $e->getMessage()]);

            return self::SUCCESS;
        }

        $last = (int) $settings->get(self::LAST_ID_KEY, 0);
        $max = $last;
        $added = 0;

        foreach ($messages as $m) {
            if ($m['id'] <= $last) {
                continue;
            }
            $max = max($max, $m['id']);
            if (self::isOptOut($m['text']) && preg_match('/^09\d{9}$/', $m['mobile'])) {
                $added += SmsOptOut::record($m['mobile'], 'reply', null, 'پاسخ: ' . mb_substr($m['text'], 0, 50)) ? 1 : 0;
            }
        }

        if ($max > $last) {
            $settings->set(self::LAST_ID_KEY, $max);
        }
        $this->info("Opt-outs recorded: {$added}");

        return self::SUCCESS;
    }

    /** «11», «لغو», «لغو11» in Persian, Arabic or Latin digits, ignoring spaces and ZWNJ. */
    public static function isOptOut(string $text): bool
    {
        $t = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', "\u{200C}" => '',
        ]);
        $t = preg_replace('/\s+/u', '', $t);

        return in_array($t, ['11', 'لغو', 'لغو11'], true);
    }
}
