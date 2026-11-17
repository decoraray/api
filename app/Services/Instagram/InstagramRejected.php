<?php

namespace App\Services\Instagram;

use RuntimeException;

/** Meta answered with an error (4xx): retrying the same call will not help. */
class InstagramRejected extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $metaCode = null, public readonly ?int $metaSubcode = null)
    {
        parent::__construct($message);
    }
}
