<?php

namespace App\Clients\SmsClient\Enums;

enum SmsTemplate: int
{
    case VERIFY_SMS = 1;

    case ORDER_DELIVERED = 3;
    case ORDER_CANCELED = 4;
    case ORDER_CONFIRMED = 5;
    case ORDER_CREATED = 6;
}
