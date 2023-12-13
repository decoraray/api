<?php

namespace App\Listeners;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Events\OrderPaid;

class SendOrderNotification
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        protected SmsProviderClientInterface $smsProvider
    )
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  \App\Events\OrderPaid  $event
     * @return void
     */
    public function handle(OrderPaid $event)
    {
        //
        if (app()->environment('local')) {
            return;
        }

        $this->smsProvider->sendSms(
            $event->user->phone,
            SmsTemplate::ORDER_CREATED,
            [
                'name' => $event->user->name,
                'password' => $event->user->one_time_pass,
            ]
        );
    }
}
