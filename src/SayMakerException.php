<?php

declare(strict_types=1);

namespace SayMaker;

/**
 * The only exception this library throws on purpose. Read $kind to decide what to do next:
 * auth (no or bad key) · quota (out of credits) · plan (model needs a plan or a pack) ·
 * busy (another run is still rendering) · rejected (reword the prompt) · timeout · other.
 */
final class SayMakerException extends \RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /** Map a refusal message to the error that says what to do next. */
    public static function classify(string $message, mixed $data = null): self
    {
        $low = strtolower($message);
        if ((is_array($data) && !empty($data['busy'])) || str_contains($low, 'is rendering') || str_contains($low, 'at once')) {
            return new self('busy', $message);
        }
        if (str_contains($low, 'api key') || str_contains($low, 'no auth')) {
            return new self('auth', $message);
        }
        if (str_contains($low, 'insufficient credits')) {
            return new self('quota', $message);
        }
        if (str_contains($low, 'subscription') || str_contains($low, 'plan') || str_contains($low, 'sign in')) {
            return new self('plan', $message);
        }
        return new self('other', $message);
    }
}
