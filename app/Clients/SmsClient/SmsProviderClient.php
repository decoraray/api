<?php

namespace App\Clients\SmsClient;

use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Clients\SmsClient\Enums\SmsTemplate;

class SmsProviderClient implements SmsProviderClientInterface, BulkSmsClientInterface
{
    private function http()
    {
        return Http::withHeaders([
            'accept' => 'application/json',
            'X-API-KEY' => config('services.sms_provider.app_key'),
        ])->timeout(30);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.sms_provider.url'), '/') . '/' . $path;
    }

    /** sms.ir answers 200 with status != 1 on some errors, so check both. */
    private function failure($response): ?string
    {
        if ($response->failed() || (int) $response->json('status') !== 1) {
            return (string) ($response->json('message') ?: 'HTTP ' . $response->status());
        }

        return null;
    }

    public function sendBulk(string $line, string $text, array $mobiles): string
    {
        $response = $this->http()->post($this->url('send/bulk'), [
            'lineNumber' => (int) $line,
            'messageText' => $text,
            'mobiles' => array_values(array_map(fn ($m) => Phone::normalize($m), $mobiles)),
        ]);

        if ($error = $this->failure($response)) {
            Log::error('Bulk sms failed', ['count' => count($mobiles), 'error' => $error]);
            throw new \Exception($error);
        }

        return (string) $response->json('data.packId');
    }

    public function receiveLatest(string $line, int $count = 100): array
    {
        $response = $this->http()->get($this->url('receive/latest'), ['count' => $count, 'lineNumber' => $line]);

        if ($error = $this->failure($response)) {
            throw new \Exception($error);
        }

        return array_map(fn ($m) => [
            'id' => (int) ($m['receiveReturnId'] ?? 0),
            'mobile' => Phone::normalize((string) ($m['mobile'] ?? '')),
            'text' => (string) ($m['messageText'] ?? ''),
            'received_at' => isset($m['receivedDateTime']) ? (int) $m['receivedDateTime'] : null,
        ], (array) $response->json('data', []));
    }

    public function sendSms(string $phone, SmsTemplate $template,  array $parameters): void
    {
        $url = config('services.sms_provider.url');
        $appKey = config('services.sms_provider.app_key');

        $list = [];
        foreach ($parameters as $name => $value) {
            $list[] = ['name' => (string) $name, 'value' => (string) $value];
        }

        $response = Http::withHeaders([
            'accept' => 'application/json',
            'X-API-KEY' => $appKey,
        ])->post($url . 'send/verify', [
            'mobile' => Phone::normalize($phone),
            'templateId' => (int) $template->templateId(),
            'parameters' => $list,
        ]);
        if ($response->failed()) {
            Log::critical('Failed to send sms', [
                'phone' => $phone,
                'template' => $template,
                'parameters' => $parameters,
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to send sms');
        }
    }
}
