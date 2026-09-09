<?php

declare(strict_types=1);

namespace Ytan\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * A deliberately simple, stateless "did a human pause for a second" check
 * (Touren.md: "einfaches Captcha" before deleting a route that belongs to
 * tours) - not a defense against a scripted attacker. Since this app has no
 * PHP session (JWT bearer auth), the challenge itself is a signed token
 * (reusing the app's existing JWT secret/library) round-tripped by the
 * client, rather than server-side session state.
 */
final class CaptchaService
{
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @return array{question:string, token:string}
     */
    public function issueChallenge(string $context): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);

        $token = JWT::encode([
            'a' => $a,
            'b' => $b,
            'ctx' => $context,
            'exp' => time() + 300,
        ], $this->secret, 'HS256');

        return ['question' => "$a + $b = ?", 'token' => $token];
    }

    /**
     * $context must match the one the challenge was issued for (e.g.
     * "route:42") so a captcha solved for one route can't be replayed
     * against a different one.
     */
    public function verify(string $token, int $answer, string $context): bool
    {
        try {
            $decoded = (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            return false;
        }

        if (($decoded['ctx'] ?? null) !== $context) {
            return false;
        }

        return ((int) ($decoded['a'] ?? -1) + (int) ($decoded['b'] ?? -1)) === $answer;
    }
}
