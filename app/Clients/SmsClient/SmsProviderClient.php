<?php

namespace App\Clients\SmsClient;

use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Clients\SmsClient\Enums\SmsTemplate;

class SmsProviderClient implements SmsProviderClientInterface
{
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
