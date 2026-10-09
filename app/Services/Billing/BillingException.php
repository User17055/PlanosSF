<?php

namespace App\Services\Billing;

class BillingException extends \RuntimeException
{
    public const KIND_AUTH = 'auth';

    public const KIND_VALIDATION = 'validation';

    public const KIND_TEMPORARY = 'temporary';

    public const KIND_UNCERTAIN = 'uncertain';

    public function __construct(string $message, public readonly string $kind = self::KIND_TEMPORARY, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Erro de validação não deve ser repetido indefinidamente (seção 12.4). */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::KIND_TEMPORARY, self::KIND_UNCERTAIN], true);
    }
}
