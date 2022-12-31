<?php

namespace App\Clients\SmsClient;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Clients\SmsClient\Enums\SmsTemplate;

class SmsProviderClient implements SmsProviderClientInterface
{
    public function sendSms(string $phone, SmsTemplate $template,  array $parameters): void
    {
        $url = config('services.sms_provider.url');
        $appKey = config('services.sms_provider.app_key');

        $response = Http::withHeaders([
            'ACCEPT' => 'application/json',
            'X-API-KEY' => $appKey,
        ])->post(
            $url,
            ['body' => json_encode(
                [
                    'Mobile' => $phone,
                    'TemplateId' => $template,
                    'Parameters' => $parameters,
                ]
            )]
        );
        if ($response->failed()) {
            dd(
                $response->json()
            );
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
