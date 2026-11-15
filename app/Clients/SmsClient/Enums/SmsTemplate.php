<?php

namespace App\Clients\SmsClient\Enums;

enum SmsTemplate: int
{
    case VERIFY_SMS = 1;

    case ORDER_DELIVERED = 3;
    case ORDER_CANCELED = 4;
    case ORDER_CONFIRMED = 5;
    case ORDER_CREATED = 6;

    /** The quote link template id is configured, not fixed. */
    case QUOTE_LINK = 0;

    public function templateId(): ?int
    {
        return match ($this) {
            self::QUOTE_LINK => filled(config('services.sms_provider.templates.quote'))
                ? (int) config('services.sms_provider.templates.quote')
                : null,
            default => $this->value,
        };
    }
}
