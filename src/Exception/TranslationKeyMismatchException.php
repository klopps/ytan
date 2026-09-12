<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * Thrown by TranslationRepository::save() when the submitted en/de maps
 * have different key sets and the caller didn't pass force=true - the
 * /translate tool must never silently drop a key that only exists in one
 * locale. Carries which keys are missing from which side so the frontend
 * can show exactly what to fix (or confirm deleting) instead of a generic
 * error. Mirrors CaptchaRequiredException's getPayload() pattern for
 * attaching structured data to an ApiException beyond message/code - see
 * App.php's error handler, which merges this into the JSON error body the
 * same way.
 */
final class TranslationKeyMismatchException extends ValidationException
{
    public function __construct(
        private readonly array $onlyInEn,
        private readonly array $onlyInDe,
    ) {
        parent::__construct('Translation key sets differ between locales.', 'translation.key_mismatch');
    }

    public function getPayload(): array
    {
        return [
            'only_in_en' => $this->onlyInEn,
            'only_in_de' => $this->onlyInDe,
        ];
    }
}
