<?php

namespace Brume\Http;

use Illuminate\Http\JsonResponse;

/**
 * The protocol's ErrorResponse, { message, errors? }, as the `_brume` endpoints answer it.
 *
 * @internal
 */
final class ErrorResponse
{
    /**
     * @param  array<string, list<string>>|null  $errors  keyed by field (the column key for a cell)
     */
    public static function make(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return new JsonResponse(['message' => $message] + ($errors !== null ? ['errors' => $errors] : []), $status);
    }
}
