<?php

namespace Tests\Feature\Campaigns;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Clients\SmsClient\SmsProviderClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkSmsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sms_provider.url' => 'https://api.sms.ir/v1/', 'services.sms_provider.app_key' => 'KEY']);
    }

    public function test_bound_in_container()
    {
        $this->assertInstanceOf(SmsProviderClient::class, app(BulkSmsClientInterface::class));
    }

    public function test_bulk_request_shape_and_pack_id()
    {
        Http::fake(['*' => Http::response(['status' => 1, 'message' => 'ok', 'data' => ['packId' => 'abc-123', 'messageIds' => [1, 2], 'cost' => 2]])]);

        $pack = (new SmsProviderClient())->sendBulk('30007732', "سلام\nلغو۱۱", ['9121234567', '۰۹۱۲۷۶۵۴۳۲۱']);

        $this->assertSame('abc-123', $pack);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.sms.ir/v1/send/bulk'
            && $r->hasHeader('X-API-KEY', 'KEY')
            && $r->data() === ['lineNumber' => 30007732, 'messageText' => "سلام\nلغو۱۱", 'mobiles' => ['09121234567', '09127654321']]);
    }

    public function test_bulk_http_error_throws()
    {
        Http::fake(['*' => Http::response(['status' => 0, 'message' => 'اعتبار کافی نیست'], 400)]);
        $this->expectExceptionMessage('اعتبار کافی نیست');
        (new SmsProviderClient())->sendBulk('3000', 'x', ['09121234567']);
    }

    public function test_bulk_status_not_one_throws()
    {
        Http::fake(['*' => Http::response(['status' => 13, 'message' => 'خط نامعتبر'], 200)]);
        $this->expectExceptionMessage('خط نامعتبر');
        (new SmsProviderClient())->sendBulk('3000', 'x', ['09121234567']);
    }

    public function test_receive_latest()
    {
        Http::fake(['*' => Http::response(['status' => 1, 'data' => [
            ['receiveReturnId' => 55, 'messageText' => 'لغو', 'number' => 30007732, 'mobile' => 9121234567, 'receivedDateTime' => 1795000000],
        ]])]);

        $rows = (new SmsProviderClient())->receiveLatest('30007732', 100);

        $this->assertSame([['id' => 55, 'mobile' => '09121234567', 'text' => 'لغو', 'received_at' => 1795000000]], $rows);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.sms.ir/v1/receive/latest?')
            && $r['count'] == 100 && $r['lineNumber'] == '30007732');
    }
}
