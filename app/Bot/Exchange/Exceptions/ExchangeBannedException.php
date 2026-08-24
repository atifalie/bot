<?php

namespace App\Bot\Exchange\Exceptions;

use RuntimeException;

class ExchangeBannedException extends RuntimeException
{
    public function __construct(
        string $operation,
        public readonly int $bannedUntilMs,
    ) {
        $remainingSec = max(0, (int) ceil(($bannedUntilMs - (microtime(true) * 1000)) / 1000));
        parent::__construct("IP ban active — {$operation} skipped, ~{$remainingSec}s remaining");
    }
}
