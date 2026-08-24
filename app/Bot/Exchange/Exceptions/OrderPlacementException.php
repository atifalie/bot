<?php

namespace App\Bot\Exchange\Exceptions;

use RuntimeException;
use Throwable;

class OrderPlacementException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?array $order = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
