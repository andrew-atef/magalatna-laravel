<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Gemini API answers 429 Too Many Requests.
 *
 * Queue jobs catch this specifically and `$this->release($seconds)` the job
 * back onto the queue instead of burning synchronous usleep() loops inside
 * the (128MB, timeout-bounded) worker process.
 */
final class GeminiRateLimitedException extends RuntimeException
{
    public function __construct(
        string $message = 'Gemini API rate limited (429).',
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
