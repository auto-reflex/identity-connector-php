<?php

namespace AutoGteck\IdentityConnector\Testing;

use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Les véhicules du faux Identity : mêmes formes, mêmes groupes, même visibilité et mêmes refus de contrôle de version que le vrai service
 * (AR-058, AR-059), pour tester un produit sans Identity. Il ne valide PAS les champs (VIN, années, doublons, règles du kilométrage) :
 * ces règles se vérifient contre le vrai service (`scripts/smoke-connector.php`).
 */
final class FakeVehicles
{
    /** Plafond des groupes par produit, comme `identity.vehicles.products` d'Identity. */
    public const CEILINGS = [
        'map' => [],
        'pro' => ['identity'],
        'autotrackly' => ['identity', 'specs', 'media', 'usage', 'sensitive'],
        'autodonuts' => ['identity', 'specs', 'media', 'usage'],
    ];

    /** @var array<string, array{owner: string, version: int, data: array<string, array<string, mixed>>, links: array<string, array{groups: list<string>, visibility: string, share_usage: bool}>}> */
    private array $vehicles = [];

    /** @var list<string> */
    private array $deleted = [];

    private int $reads = 0;

    public function __construct(private readonly string $product) {}

    /**
     * Déclare un véhicule. `$data` est groupé (`identity`, `specs`, `usage`, `sensitive`…), `$links` par produit
     * (`['autodonuts' => ['groups' => ['identity'], 'visibility' => 'public']]`).
     *
     * @param  array<string, array<string, mixed>>  $data
     * @param  array<string, array<string, mixed>>  $links
     */
    public function add(string $id, string $ownerUserId, array $data, array $links = []): void
    {
        $this->vehicles[$id] = [
            'owner' => $ownerUserId,
            'version' => 1,
            'data' => $data + ['identity' => [], 'specs' => [], 'media' => ['photos' => [], 'primary_photo' => null], 'usage' => []],
            'links' => array_map(fn (array $link) => $link + ['visibility' => 'private', 'share_usage' => false], $links),
        ];
    }

    /**
     * @return array{owner: string, version: int, data: array<string, array<string, mixed>>, links: array<string, array<string, mixed>>}|null
     */
    public function find(string $id): ?array
    {
        return $this->vehicles[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function deleted(): array
    {
        return $this->deleted;
    }

    /**
     * Nombre de lectures (GET) reçues : pour vérifier le cache du connecteur.
     */
    public function reads(): int
    {
        return $this->reads;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return PromiseInterface
     */
    public function handle(Request $request, array $token, string $path)
    {
        $scopes = explode(' ', (string) ($token['scope'] ?? ''));
        $service = ($token['sub'] ?? null) === ($token['client_id'] ?? '');
        $person = $service ? null : (string) $token['sub'];
        $method = $request->method();
        $write = $method !== 'GET';

        if (! in_array($write ? 'vehicles:write' : 'vehicles:read', $scopes, true)) {
            return Http::response(['error' => 'insufficient_scope'], 403);
        }

        if ($write && $service) {
            return Http::response(['error' => 'insufficient_scope'], 403);
        }

        $reader = 'member';

        if ($service) {
            $reader = (string) ($request->header('X-Reader')[0] ?? '');

            if (! in_array($reader, ['member', 'anonymous'], true)) {
                return Http::response(['error' => 'invalid_reader'], 400);
            }
        }

        $context = ['person' => $person, 'scopes' => $scopes, 'reader' => $reader];

        if ($path === '/api/v1/vehicles') {
            return $method === 'POST' ? $this->create($request, $context) : $this->list($request, $context);
        }

        if (preg_match('#^/api/v1/vehicles/([^/]+)(/link)?$#', $path, $matches) !== 1) {
            return Http::response(['error' => 'not_found'], 404);
        }

        $id = rawurldecode($matches[1]);

        if (($matches[2] ?? '') === '/link') {
            return $this->link($request, $id, $context);
        }

        return match ($method) {
            'GET' => $this->show($request, $id, $context),
            'PATCH' => $this->update($request, $id, $context),
            'DELETE' => $this->destroy($id, $context),
            default => Http::response(['error' => 'not_found'], 404),
        };
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function show(Request $request, string $id, array $context)
    {
        $this->reads++;
        $projection = $this->project($id, $context, $this->fields($request));

        return $projection === null
            ? Http::response(['error' => 'not_found'], 404)
            : Http::response(['data' => $projection[0]], 200, ['Cache-Control' => $projection[1] ? 'no-store' : 'private, max-age=60']);
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function list(Request $request, array $context)
    {
        $this->reads++;
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $fields = $this->fields($request);
        $ids = isset($query['ids']) ? array_values(array_filter(explode(',', (string) $query['ids']))) : null;

        if ($ids === null && $context['person'] === null) {
            return Http::response(['error' => 'ids_required'], 400);
        }

        $ids ??= array_keys(array_filter($this->vehicles, fn (array $vehicle) => $vehicle['owner'] === $context['person']));
        $data = [];
        $sensitive = false;

        foreach ($ids as $id) {
            if (($projection = $this->project($id, $context, $fields)) !== null) {
                $data[] = $projection[0];
                $sensitive = $sensitive || $projection[1];
            }
        }

        return Http::response(['data' => $data], 200, ['Cache-Control' => $sensitive ? 'no-store' : 'private, max-age=60']);
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function create(Request $request, array $context)
    {
        $body = $request->data();

        if (! isset($body['identity']['make'], $body['identity']['model'])) {
            return Http::response(['error' => 'validation_failed', 'errors' => ['identity' => ['make and model are required.']]], 422);
        }

        $id = strtolower((string) Str::ulid());
        $links = isset($body['link']) ? [$this->product => $this->normalizeLink($body['link'])] : [];
        $this->add($id, (string) $context['person'], array_intersect_key($body, array_flip(['identity', 'specs', 'usage', 'sensitive'])), $links);

        $projection = $this->project($id, $context, null);

        return Http::response(['data' => $projection[0] ?? ['id' => $id]], 201);
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function update(Request $request, string $id, array $context)
    {
        $vehicle = $this->vehicles[$id] ?? null;

        if ($vehicle === null || $vehicle['owner'] !== $context['person']) {
            return Http::response(['error' => 'not_found'], 404);
        }

        if (! preg_match('/^\s*(?:W\/)?"?(\d+)/', (string) ($request->header('If-Match')[0] ?? ''), $matches)) {
            return Http::response(['error' => 'precondition_required'], 428);
        }

        if ((int) $matches[1] !== $vehicle['version']) {
            return Http::response(['error' => 'precondition_failed', 'current_version' => $vehicle['version']], 412);
        }

        $granted = $vehicle['links'][$this->product]['groups'] ?? [];

        foreach (array_intersect_key($request->data(), array_flip(['identity', 'specs', 'usage', 'sensitive'])) as $group => $fields) {
            if (! in_array($group, $granted, true)) {
                return Http::response(['error' => 'group_not_allowed', 'groups' => [$group]], 403);
            }

            $this->vehicles[$id]['data'][$group] = array_replace($vehicle['data'][$group] ?? [], (array) $fields);
        }

        $this->vehicles[$id]['version']++;
        $projection = $this->project($id, $context, null);

        return Http::response(['data' => $projection[0] ?? ['id' => $id]]);
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function destroy(string $id, array $context)
    {
        if (($this->vehicles[$id]['owner'] ?? null) !== $context['person']) {
            return Http::response(['error' => 'not_found'], 404);
        }

        unset($this->vehicles[$id]);
        $this->deleted[] = $id;

        return Http::response('', 204);
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @return PromiseInterface
     */
    private function link(Request $request, string $id, array $context)
    {
        if (($this->vehicles[$id]['owner'] ?? null) !== $context['person']) {
            return Http::response(['error' => 'not_found'], 404);
        }

        if ($request->method() === 'DELETE') {
            if (! isset($this->vehicles[$id]['links'][$this->product])) {
                return Http::response(['error' => 'not_found'], 404);
            }

            unset($this->vehicles[$id]['links'][$this->product]);

            return Http::response('', 204);
        }

        $created = ! isset($this->vehicles[$id]['links'][$this->product]);
        $link = $this->normalizeLink($request->data());
        $this->vehicles[$id]['links'][$this->product] = $link;

        return Http::response(['data' => ['product' => $this->product] + $link + ['linked_at' => Carbon::now()->toIso8601String()]], $created ? 201 : 200);
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array{groups: list<string>, visibility: string, share_usage: bool}
     */
    private function normalizeLink(array $link): array
    {
        return [
            'groups' => array_values((array) ($link['groups'] ?? [])),
            'visibility' => (string) ($link['visibility'] ?? 'private'),
            'share_usage' => (bool) ($link['share_usage'] ?? false),
        ];
    }

    /**
     * @param  array{person: string|null, scopes: list<string>, reader: string}  $context
     * @param  list<string>|null  $fields
     * @return array{0: array<string, mixed>, 1: bool}|null la fiche projetée et si elle porte `sensitive` ; `null` = 404
     */
    private function project(string $id, array $context, ?array $fields): ?array
    {
        $vehicle = $this->vehicles[$id] ?? null;

        if ($vehicle === null) {
            return null;
        }

        $ownerSide = $context['person'] !== null && $vehicle['owner'] === $context['person'];
        $ceiling = self::CEILINGS[$this->product] ?? [];
        $link = $vehicle['links'][$this->product] ?? null;

        if ($ownerSide) {
            $groups = array_values(array_intersect($ceiling, $link === null ? ['identity', 'media'] : $link['groups']));

            if (! in_array('vehicles:sensitive', $context['scopes'], true)) {
                $groups = array_values(array_diff($groups, ['sensitive']));
            }
        } else {
            $visible = $link !== null && match ($link['visibility']) {
                'public' => true,
                'product' => $context['reader'] === 'member',
                default => false,
            };

            if (! $visible) {
                return null;
            }

            $groups = array_values(array_intersect($ceiling, $link['groups'], ['identity', 'specs', 'media']));

            if ($link['share_usage'] && in_array('usage', $link['groups'], true) && in_array('usage', $ceiling, true)) {
                $groups[] = 'usage';
            }
        }

        if ($groups === []) {
            return null;
        }

        $shown = array_values(array_intersect($groups, $fields ?? array_diff($groups, ['sensitive'])));
        $data = ['id' => $id, 'version' => $vehicle['version']];

        foreach ($shown as $group) {
            $data[$group] = $vehicle['data'][$group] ?? [];
        }

        if ($ownerSide) {
            $data += ['owner' => ['type' => 'user', 'id' => $vehicle['owner']], 'role' => 'owner', 'link' => $link === null ? null : ['product' => $this->product] + $link];
        }

        return [$data, in_array('sensitive', $shown, true)];
    }

    /**
     * @return list<string>|null
     */
    private function fields(Request $request): ?array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['fields']) && $query['fields'] !== '' ? explode(',', (string) $query['fields']) : null;
    }
}
