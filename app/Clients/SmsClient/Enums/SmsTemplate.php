<?php

namespace App\Clients\SmsClient\Enums;

/** The value of each case is its key under services.sms_provider.templates. */
enum SmsTemplate: string
{
    case QUOTE_LINK = 'quote';
    case ORDER_PAID = 'order_paid';
    case ORDER_CONFIRMED = 'order_confirmed';
    case ORDER_SHIPPED = 'order_shipped';
    case ORDER_CANCELED = 'order_canceled';
    case STAFF_REMINDER = 'staff_reminder';

    public function templateId(): ?int
    {
        $id = config('services.sms_provider.templates.' . $this->value);

        return filled($id) ? (int) $id : null;
    }
}
