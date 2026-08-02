<?php

declare(strict_types=1);

namespace Jot\Exception;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        public int $status,
        public string $publicMessage,
        string $diagnostic = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($diagnostic !== '' ? $diagnostic : $publicMessage, 0, $previous);
    }
}
