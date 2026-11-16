<?php

namespace App\Console\Commands;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Models\StaffReminder;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily SMS digest of each salesperson's reminders. Scheduled every few
 * minutes; it only acts during the configured Tehran hour and sends at most
 * one digest per person per day (unique staff_reminders row).
 */
class SendReminders extends Command
{
    protected $signature = 'reminders:send {--force : Ignore the configured hour}';

    protected $description = 'Send the daily reminder SMS digest to sales staff';

    public function handle(ReminderService $reminders): int
    {
        if (!ReminderService::enabled()) {
            $this->info('Reminders are disabled in settings.');

            return self::SUCCESS;
        }

        $now = Carbon::now('Asia/Tehran');
        if (!$this->option('force') && $now->hour !== ReminderService::hour()) {
            return self::SUCCESS;
        }

        $date = $now->toDateString();
        $byOwner = $reminders->byOwner();
        $sent = 0;

        foreach (User::whereIn('id', array_keys($byOwner))->orderBy('id')->get() as $user) {
            try {
                $sent += $this->digest($user, $reminders->for($user, $byOwner), $date) ? 1 : 0;
            } catch (Throwable $e) {
                Log::error('Reminder digest failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Reminder digests sent: {$sent}");

        return self::SUCCESS;
    }

    /** Record and send one person's digest. Returns true when an SMS went out. */
    private function digest(User $user, array $sections, string $date): bool
    {
        $counts = [
            'follow_ups' => isset($sections['follow_ups']) ? $sections['follow_ups']->count() : 0,
            'idle_leads' => isset($sections['idle_leads']) ? $sections['idle_leads']->count() : 0,
            'open_quotes' => isset($sections['open_quotes']) ? $sections['open_quotes']->count() : 0,
        ];
        if (array_sum($counts) === 0) {
            return false;
        }

        // Claim today's slot first; a second run finds the row and stops.
        $claimed = StaffReminder::insertOrIgnore([
            'user_id' => $user->id, 'date' => $date, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ] + $counts);
        if (!$claimed) {
            return false;
        }
        $row = StaffReminder::where('user_id', $user->id)->where('date', $date)->first();

        $template = SmsTemplate::STAFF_REMINDER;
        if ($template->templateId() === null) {
            $row->update(['status' => 'skipped', 'error' => 'no template']);

            return false;
        }

        $phone = Phone::normalize($user->phone);
        if (!preg_match('/^09\d{9}$/', $phone)) {
            $row->update(['status' => 'failed', 'error' => 'invalid phone']);

            return false;
        }

        try {
            app(SmsProviderClientInterface::class)->sendSms($phone, $template, [
                'NAME' => explode(' ', trim((string) $user->name))[0] ?: 'همکار',
                'FOLLOWUPS' => $counts['follow_ups'],
                'IDLE' => $counts['idle_leads'],
                'QUOTES' => $counts['open_quotes'],
            ]);
        } catch (Throwable $e) {
            $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);

            return false;
        }

        $row->update(['status' => 'sent']);

        return true;
    }
}
