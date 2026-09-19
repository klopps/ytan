<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * Thrown by PoiRepository/RouteRepository/AreaRepository's update()/delete()
 * when the caller supplied an expected_updated_at that no longer matches the
 * row's current updated_at - i.e. the record changed on the server since the
 * caller last knew about it (todo.md's "Offline-Funktionalität" Phase 3:
 * offline-sync.js's queued sync is the only caller that opts into this
 * check, see the repositories' own doc comments). Carries the record's
 * current, authoritative server state so the frontend can show a
 * "server wins vs. app wins" resolution without a second round-trip.
 */
final class ConflictException extends ApiException
{
    public function __construct(private readonly array $serverRecord)
    {
        parent::__construct('This record was modified on the server since it was last loaded.', 409);
    }

    public function getPayload(): array
    {
        return ['server' => $this->serverRecord];
    }
}
