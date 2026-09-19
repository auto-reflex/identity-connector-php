<?php

namespace AutoReflex\IdentityConnector\Http\Controllers;

use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
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
        if (! in_array($envelope['type'], ['account.suspended', 'account.reinstated'], true)) {
            return response()->json(['status' => 'ignored'], 202);
        }

        if ($envelope['version'] !== self::SUPPORTED_VERSION) {
            Log::warning("Identity webhook {$envelope['type']} in version {$envelope['version']} ignored: update the connector.");

            return response()->json(['status' => 'ignored'], 202);
        }

        $userId = $envelope['data']['user_id'] ?? null;

        if (! is_string($userId) || $userId === '' || strlen($userId) > 64) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        $key = 'identity-connector:webhook:'.$envelope['id'];

        if (! $this->cache->add($key, 1, now()->addDays((int) config('identity-connector.webhooks.replay_days')))) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $occurredAt = CarbonImmutable::parse($envelope['occurred_at']);
            $event = $envelope['type'] === 'account.suspended'
                ? new AccountSuspended($userId, $envelope['id'], $occurredAt)
                : new AccountReinstated($userId, $envelope['id'], $occurredAt);

            event($event);
        } catch (Throwable $exception) {
            // Le traitement a échoué : Identity doit pouvoir renvoyer l'événement, on ne le marque pas comme traité.
            $this->cache->forget($key);

            throw $exception;
        }

        return response()->json(null, 204);
    }
}
