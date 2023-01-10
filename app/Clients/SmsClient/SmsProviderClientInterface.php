<?php

namespace App\Clients\SmsClient;

use App\Clients\SmsClient\Enums\SmsTemplate;

interface SmsProviderClientInterface
{
    public function sendSms(string $phone, SmsTemplate $template, array $parameters): void;
}
