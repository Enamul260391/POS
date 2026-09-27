<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A business rule was violated by an otherwise well-formed request.
 *
 * Used when the rule cannot be expressed as field validation because it
 * depends on stored state — e.g. "this category is still referenced by
 * products" or "only 3 units are on hand". The service layer authors the
 * message (domain wording, safe to expose) and picks the status code;
 * {@see ApiExceptionRenderer} turns it into the standard error envelope.
 *
 * Nothing internal (SQL, file paths, stack traces) may ever be passed as the
 * message.
 */
final class BusinessRuleException extends RuntimeException
{
    /** Status used when the caller does not pick a more specific one. */
    public const DEFAULT_STATUS = 409;

    public function __construct(
        string $message,
        private readonly int $status = self::DEFAULT_STATUS,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * HTTP status that represents this rule violation.
     */
    public function status(): int
    {
        return $this->status;
    }
}
