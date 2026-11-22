<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Error de reglas contables/financieras. Lleva la lista de motivos para devolverla al cliente. */
final class LedgerException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, public readonly string $errorCode = 'ledger_error')
    {
        parent::__construct(implode(' | ', $errors));
    }

    public static function of(string $message, string $code = 'ledger_error'): self
    {
        return new self([$message], $code);
    }
}
