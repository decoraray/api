<?php

namespace Tests\Feature;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsProviderClientTest extends TestCase
{
    public function test_quote_link_request_shape()
    {
        config([
            'services.sms_provider.url' => 'https://api.sms.ir/v1/',
            'services.sms_provider.app_key' => 'KEY',
            'services.sms_provider.templates.quote' => '777',
        ]);
        Http::fake();

        (new SmsProviderClient())->sendSms('۰۹۱۲۳۴۵۶۷۸۹', SmsTemplate::QUOTE_LINK, ['LINK' => 'https://decoraray.ir/q/abc']);

        Http::assertSent(function (Request $r) {
            return str_ends_with($r->url(), 'send/verify')
                && $r->hasHeader('X-API-KEY', 'KEY')
                && $r->data() === [
                    'mobile' => '09123456789',
                    'templateId' => 777,
                    'parameters' => [['name' => 'LINK', 'value' => 'https://decoraray.ir/q/abc']],
                ];
        });
    }

    public function test_failure_throws()
    {
        config(['services.sms_provider.url' => 'https://x/', 'services.sms_provider.templates.quote' => '1']);
        Http::fake(['*' => Http::response(['e' => 1], 500)]);
        $this->expectException(\Exception::class);
        (new SmsProviderClient())->sendSms('09123456789', SmsTemplate::QUOTE_LINK, ['LINK' => 'x']);
    }
}
