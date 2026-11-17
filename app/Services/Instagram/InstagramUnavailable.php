<?php

namespace App\Services\Instagram;

use RuntimeException;

/** The relay or Meta could not be reached (timeout, connection, 5xx, 429): safe to retry. */
class InstagramUnavailable extends RuntimeException
{
}
