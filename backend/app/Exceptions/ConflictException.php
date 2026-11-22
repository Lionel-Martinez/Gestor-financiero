<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Conflicto de versión: se conservan ambas versiones y se exige resolución explícita (no hay last-write-wins). */
final class ConflictException extends RuntimeException
{
    /** @param array<string,mixed> $serverState */
    public function __construct(public readonly array $serverState)
    {
        parent::__construct('La versión base no coincide con la del servidor.');
    }
}
