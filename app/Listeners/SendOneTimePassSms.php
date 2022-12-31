<?php

namespace App\Listeners;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;


class SendOneTimePassSms
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        protected SmsProviderClientInterface $smsProvider
    ) {
        //
    }

    /**
     * Handle the event.
     *
     * @param  \App\Events\OneTimePassGenerated  $event
     * @return void
     */
    public function handle($event)
    {
        if (app()->environment('local')) {
            return;
        }

        $this->smsProvider->sendSms(
            $event->user->phone,
            SmsTemplate::VERIFY_SMS,
            [
                'name' => $event->user->name,
                'password' => $event->user->one_time_pass,
            ]
        );
    }
}
