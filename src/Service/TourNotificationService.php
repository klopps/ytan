<?php

declare(strict_types=1);

namespace Ytan\Service;

use Throwable;
use Ytan\Domain\User\UserRepository;

/**
 * Emails tour creators when a route in their tour is changed or deleted,
 * and emails a route's owner when their public route is auto-unpublished
 * as part of a delete (Touren.md's "Verhalten" section). Kept separate from
 * RouteController/TourRepository since it's the only thing in this feature
 * that needs both UserRepository and MailService together.
 *
 * Notifications are best-effort: a mail failure (e.g. no SMTP reachable in
 * a dev environment) must never fail the route update/delete itself, so
 * every send is wrapped and swallowed here rather than left to bubble up.
 */
final class TourNotificationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $affectedTours rows from TourRepository::findByRoute()
     */
    public function notifyRouteChanged(array $affectedTours, array $route): void
    {
        foreach ($affectedTours as $tour) {
            $this->notifyOwner($tour, function (string $email) use ($tour, $route) {
                $link = $this->appUrl . '/#tour=' . $tour['id'];
                $this->mail->sendTourRouteChanged($email, $tour['name'], $route['name'], $link);
            });
        }
    }

    /**
     * @param array<int,array<string,mixed>> $affectedTours
     */
    public function notifyRouteDeleted(array $affectedTours, array $route): void
    {
        foreach ($affectedTours as $tour) {
            $this->notifyOwner($tour, function (string $email) use ($tour, $route) {
                $this->mail->sendTourRouteDeleted($email, $tour['name'], $route['name']);
            });
        }
    }

    public function notifyRouteAutoUnpublished(array $route): void
    {
        $owner = $this->users->findById((int) $route['user_id']);
        if ($owner === null || empty($owner['email'])) {
            return;
        }

        try {
            $this->mail->sendRouteAutoUnpublished($owner['email'], $route['name']);
        } catch (Throwable $e) {
            error_log('sendRouteAutoUnpublished() failed: ' . $e->getMessage());
        }
    }

    private function notifyOwner(array $tour, callable $send): void
    {
        $owner = $this->users->findById((int) $tour['user_id']);
        if ($owner === null || empty($owner['email'])) {
            return;
        }

        try {
            $send($owner['email']);
        } catch (Throwable $e) {
            error_log('Tour notification failed: ' . $e->getMessage());
        }
    }
}
