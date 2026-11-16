<?php

namespace App\Clients\SmsClient;

/** Free-text sending from a line and reading replies to it (campaigns). */
interface BulkSmsClientInterface
{
    /**
     * Send one text to many mobiles from a line. Returns the provider's pack id.
     *
     * @param  array<int, string>  $mobiles
     *
     * @throws \Exception when the provider rejects the request
     */
    public function sendBulk(string $line, string $text, array $mobiles): string;

    /**
     * The latest messages received on a line, newest provider ids included.
     *
     * @return array<int, array{id: int, mobile: string, text: string, received_at: ?int}>
     */
    public function receiveLatest(string $line, int $count = 100): array;
}
