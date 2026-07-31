<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when code attempts to mutate an append-only record (audit logs now;
 * journal entries and their lines in a later phase, per spec §8).
 */
final class ImmutableRecordException extends DomainException
{
    public static function cannotUpdate(string $model): self
    {
        return new self(
            sprintf('%s records are append-only and cannot be updated.', class_basename($model)),
            ErrorCode::ImmutableRecord,
            Response::HTTP_CONFLICT,
        );
    }

    public static function cannotDelete(string $model): self
    {
        return new self(
            sprintf('%s records are append-only and cannot be deleted.', class_basename($model)),
            ErrorCode::ImmutableRecord,
            Response::HTTP_CONFLICT,
        );
    }
}
