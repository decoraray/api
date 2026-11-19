<?php

namespace App\Services\Production\Drafts;

use RuntimeException;

/** A draft cannot be made: settings missing, or parameters that give impossible parts. The message is Persian. */
class DraftException extends RuntimeException
{
}
