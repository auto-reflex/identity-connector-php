<?php

namespace AutoGteck\IdentityConnector\Http\Controllers;

use AutoGteck\IdentityConnector\Events\AccountDeletionCancelled;
use AutoGteck\IdentityConnector\Events\AccountDeletionDue;
use AutoGteck\IdentityConnector\Events\AccountDeletionRequested;
use AutoGteck\IdentityConnector\Events\AccountReinstated;
use AutoGteck\IdentityConnector\Events\AccountSuspended;
use AutoGteck\IdentityConnector\Events\OrganizationDeleted;
use AutoGteck\IdentityConnector\Events\VehicleDeleted;
use AutoGteck\IdentityConnector\Events\VehicleUnlinked;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reçoit les événements d'Identity (AR-053) et les republie comme événements Laravel locaux.
 * Livraison au moins une fois : un événement déjà traité est reconnu (200) sans être rejoué.
 */
class IdentityWebhookController
{
    private const SUPPORTED_VERSION = 1;

    private const TYPES = [
        'account.suspended', 'account.reinstated', 'account.deletion_requested',
        'account.deletion_cancelled', 'account.deletion_due', 'organization.deleted', 'vehicle.deleted', 'vehicle.unlinked',
    ];

    public function __construct(private readonly Cache $cache) {}

    public function __invoke(Request $request): JsonResponse
    {
        $envelope = json_decode($request->getContent(), true);

        if (! is_array($envelope)
            || ! is_string($envelope['id'] ?? null) || $envelope['id'] === '' || strlen($envelope['id']) > 64
            || ! is_string($envelope['type'] ?? null)
            || ! is_int($envelope['version'] ?? null)
            || ! is_string($envelope['occurred_at'] ?? null)
            || ! is_array($envelope['data'] ?? null)) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        // Un type ou une version que ce produit ne connaît pas n'est pas une erreur : Identity peut évoluer.
        if (! in_array($envelope['type'], self::TYPES, true)) {
            return response()->json(['status' => 'ignored'], 202);
        }

        if ($envelope['version'] !== self::SUPPORTED_VERSION) {
            Log::warning("Identity webhook {$envelope['type']} in version {$envelope['version']} ignored: update the connector.");

            return response()->json(['status' => 'ignored'], 202);
        }

        // Un événement d'organisation porte `organization_id`, un événement de véhicule `vehicle_id`, les autres `user_id`.
        $subject = $envelope['data'][match ($envelope['type']) {
            'organization.deleted' => 'organization_id',
            'vehicle.deleted', 'vehicle.unlinked' => 'vehicle_id',
            default => 'user_id',
        }] ?? null;

        $product = $envelope['data']['product'] ?? null;

        if ($envelope['type'] === 'vehicle.unlinked' && (! is_string($product) || $product === '' || strlen($product) > 32)) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        if (! is_string($subject) || $subject === '' || strlen($subject) > 64) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        $scheduledFor = $envelope['data']['scheduled_for'] ?? null;

        if ($envelope['type'] === 'account.deletion_requested' && ! is_string($scheduledFor)) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        $key = 'identity-connector:webhook:'.$envelope['id'];

        if (! $this->cache->add($key, 1, now()->addDays((int) config('identity-connector.webhooks.replay_days')))) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $occurredAt = CarbonImmutable::parse($envelope['occurred_at']);
            $event = match ($envelope['type']) {
                'account.suspended' => new AccountSuspended($subject, $envelope['id'], $occurredAt),
                'account.reinstated' => new AccountReinstated($subject, $envelope['id'], $occurredAt),
                'account.deletion_requested' => new AccountDeletionRequested($subject, $envelope['id'], $occurredAt, CarbonImmutable::parse((string) $scheduledFor)),
                'account.deletion_cancelled' => new AccountDeletionCancelled($subject, $envelope['id'], $occurredAt),
                'account.deletion_due' => new AccountDeletionDue($subject, $envelope['id'], $occurredAt),
                'organization.deleted' => new OrganizationDeleted($subject, $envelope['id'], $occurredAt),
                'vehicle.deleted' => new VehicleDeleted($subject, $envelope['id'], $occurredAt),
                'vehicle.unlinked' => new VehicleUnlinked($subject, (string) $product, $envelope['id'], $occurredAt),
            };

            event($event);
        } catch (Throwable $exception) {
            // Le traitement a échoué : Identity doit pouvoir renvoyer l'événement, on ne le marque pas comme traité.
            $this->cache->forget($key);

            throw $exception;
        }

        return response()->json(null, 204);
    }
}
