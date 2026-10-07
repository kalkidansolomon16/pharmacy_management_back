<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A domain rule was violated (insufficient stock, expired prescription, ...).
 * Rendered as 422 with the same shape as validation errors.
 */
class BusinessRuleException extends Exception
{
    public function __construct(string $message, protected array $errors = [], protected int $status = 422)
    {
        parent::__construct($message);
    }

    public static function withErrors(string $field, string $message): self
    {
        return new self($message, [$field => [$message]]);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'errors' => $this->errors ?: null,
        ]), $this->status);
    }
}
