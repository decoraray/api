<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * A payment gateway refused or could not handle a request.
 *
 * The message carries the gateway's own explanation (title, detail, trace id)
 * so it is visible in the log and in Sentry; context() adds the raw response.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $gateway,
        public readonly ?int $status = null,
        public readonly mixed $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return [
            'gateway' => $this->gateway,
            'status' => $this->status,
            'response' => $this->response,
        ];
    }
}
