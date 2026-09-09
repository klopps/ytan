<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * Thrown when deleting a route that belongs to one or more tours and no
 * valid captcha token/answer was supplied yet (Touren.md's "warn + simple
 * captcha before deleting a tour route" flow). Carries the challenge so the
 * frontend can show it and retry the same DELETE with the answer attached.
 */
final class CaptchaRequiredException extends ApiException
{
    public function __construct(
        private readonly int $tourCount,
        private readonly string $question,
        private readonly string $token,
    ) {
        parent::__construct(
            "This route is part of $tourCount tour(s). Solve the captcha to confirm deletion.",
            422
        );
    }

    /**
     * Extra fields merged into the JSON error body by App.php's error
     * handler, alongside the standard {"error":{"message":...}} shape.
     */
    public function getPayload(): array
    {
        return [
            'tour_count' => $this->tourCount,
            'captcha' => ['question' => $this->question, 'token' => $this->token],
        ];
    }
}
