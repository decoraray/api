<?php

namespace App\Services\Orders;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderNotifier
{
    public const EVENTS = [
        'paid' => SmsTemplate::ORDER_PAID,
        'confirmed' => SmsTemplate::ORDER_CONFIRMED,
        'shipped' => SmsTemplate::ORDER_SHIPPED,
        'canceled' => SmsTemplate::ORDER_CANCELED,
    ];

    /** The notification event a status change triggers, if any. */
    public static function eventForStatus(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::CONFIRMED => 'confirmed',
            OrderStatus::SHIPPING => 'shipped',
            OrderStatus::CANCELED => 'canceled',
            default => null,
        };
    }

    /** Send the event's SMS after the response; never throws. */
    public function notify(Order $order, string $event): void
    {
        $orderId = $order->getKey();

        dispatch(function () use ($orderId, $event) {
            $order = Order::find($orderId);
            if ($order) {
                app(OrderNotifier::class)->notifyNow($order, $event);
            }
        })->afterResponse();
    }

    /** Synchronous check, send and record. */
    public function notifyNow(Order $order, string $event): void
    {
        try {
            $template = self::EVENTS[$event] ?? null;
            if (!$template) {
                return;
            }

            $already = OrderNotification::where('order_id', $order->id)
                ->where('event', $event)->where('status', 'sent')->exists();
            if ($already) {
                return;
            }

            $record = fn (string $status, ?string $phone, ?string $error = null) => OrderNotification::create([
                'order_id' => $order->id,
                'event' => $event,
                'phone' => $phone,
                'status' => $status,
                'error' => $error,
            ]);

            $phone = Phone::normalize($order->phone);
            if (!preg_match('/^09\d{9}$/', $phone)) {
                // factory/checkout numbers may lack the leading zero
                $phone = preg_match('/^9\d{9}$/', $phone) ? '0' . $phone : $phone;
            }

            if ($template->templateId() === null) {
                $record('skipped', $phone ?: null);
                return;
            }

            if (!preg_match('/^09\d{9}$/', $phone)) {
                $record('failed', $order->phone, 'invalid phone');
                return;
            }

            try {
                app(SmsProviderClientInterface::class)->sendSms($phone, $template, $this->parameters($order));
                $record('sent', $phone);
            } catch (Throwable $e) {
                $record('failed', $phone, $e->getMessage());
            }
        } catch (Throwable $e) {
            Log::error('Order notification failed', ['order' => $order->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function parameters(Order $order): array
    {
        $name = trim((string) ($order->user?->name ?? ''));
        $base = rtrim(config('app.front_url') ?: 'https://decoraray.ir', '/');

        return [
            'NAME' => explode(' ', $name)[0] ?? '',
            'ORDER' => substr($order->uuid, -6),
            'LINK' => $base . '/profile/orders#order-' . $order->uuid,
        ];
    }
}
