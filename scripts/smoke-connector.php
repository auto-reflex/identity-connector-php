<?php

/*
 * Porte de sortie de la phase 2 (ROADMAP) contre un vrai Identity : l'API de test du connecteur (workbench)
 * authentifie une personne, crée son profil, lit l'API Identity avec le bon token, et reçoit les webhooks.
 *
 *   php scripts/smoke-connector.php
 *
 * Le script lance lui-même Identity (:8100), son worker de file et le workbench (:8110), puis les arrête.
 * Prérequis : le dépôt `identity/` à côté de celui-ci (ou IDENTITY_DIR), sa base locale PostgreSQL démarrée,
 * Mailpit sur :8028 (MAILPIT_URL). Il applique les migrations de la base locale d'Identity, y injecte les
 * clients des produits et fixe le secret du client de service `autodonuts-api-service` (valeurs de
 * développement de testbench.yaml).
 */

require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Process\Process;

const IDENTITY_URL = 'http://localhost:8100';
const WORKBENCH_URL = 'http://127.0.0.1:8110';
const WEBHOOK_SECRET = 'smoke-webhook-secret-0123456789abcdef';
const SERVICE_SECRET = 'smoke-service-secret-0123456789abcdef';

$connectorDir = dirname(__DIR__);
$identityDir = realpath(getenv('IDENTITY_DIR') ?: $connectorDir.'/../identity');
$mailpit = rtrim(getenv('MAILPIT_URL') ?: 'http://localhost:8028', '/');

function step(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  ✓ ' : '  ✗ ').$label.($detail !== '' ? " — {$detail}" : '')."\n";

    if (! $ok) {
        exit(1);
    }
}

function field(string $html, string $name): string
{
    preg_match('/name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '');
}

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

if ($identityDir === false || ! is_file($identityDir.'/artisan')) {
    fwrite(STDERR, "Dépôt identity/ introuvable : définissez IDENTITY_DIR.\n");
    exit(1);
}

$identityEnv = [
    'IDENTITY_WEBHOOK_AUTODONUTS_URL' => WORKBENCH_URL.'/identity/webhooks',
    'IDENTITY_WEBHOOK_AUTODONUTS_SECRET' => WEBHOOK_SECRET,
    'PHP_CLI_SERVER_WORKERS' => '4',
];

/** @var list<Process> $children */
$children = [];
register_shutdown_function(function () use (&$children): void {
    foreach ($children as $process) {
        $process->stop(3);
    }
    // Le serveur HTTP intégré lance des workers, et `testbench serve` un sous-processus : on les arrête par leur port.
    exec('pkill -f "php -S localhost:8100" 2>/dev/null; pkill -f "php -S 127.0.0.1:8110" 2>/dev/null');
});

/**
 * @param  list<string>  $command
 */
$run = function (array $command, string $cwd, array $env = []): string {
    $process = new Process($command, $cwd, $env, timeout: 120);
    $process->run();

    if (! $process->isSuccessful()) {
        fwrite(STDERR, $process->getOutput().$process->getErrorOutput());
        exit(1);
    }

    return $process->getOutput();
};

/**
 * Exécute du PHP dans l'application Identity (tinker) et renvoie la dernière ligne JSON imprimée.
 *
 * @return array<string, mixed>
 */
$tinker = function (string $code) use ($run, $identityDir, $identityEnv): array {
    $output = $run([PHP_BINARY, 'artisan', 'tinker', '--execute='.$code], $identityDir, $identityEnv);

    foreach (array_reverse(preg_split('/\R/', trim($output))) as $line) {
        if (str_starts_with(trim($line), '{')) {
            return json_decode(trim($line), true) ?? [];
        }
    }

    return [];
};

$waitFor = function (callable $check, int $seconds = 25) {
    for ($i = 0; $i < $seconds * 4; $i++) {
        if (($result = $check()) !== null && $result !== false) {
            return $result;
        }
        usleep(250_000);
    }

    return null;
};

foreach ([8100, 8110] as $port) {
    if (($socket = @fsockopen('127.0.0.1', $port, timeout: 0.5)) || ($socket = @fsockopen('::1', $port, timeout: 0.5))) {
        fclose($socket);
        fwrite(STDERR, "Le port {$port} est déjà utilisé : arrêtez le serveur qui l'occupe avant le smoke.\n");
        exit(1);
    }
}

echo "Préparation : Identity ({$identityDir}) et workbench\n";

$run([PHP_BINARY, 'artisan', 'migrate', '--force'], $identityDir, $identityEnv);
$run([PHP_BINARY, 'artisan', 'db:seed', '--class=Database\\Seeders\\ClientsSeeder', '--force'], $identityDir, $identityEnv);
$tinker('App\Modules\Auth\OAuth\OAuthClient::query()->findOrFail("autodonuts-api-service")->forceFill(["secret" => "'.SERVICE_SECRET.'"])->save(); echo json_encode(["ok" => true]);');

touch($connectorDir.'/workbench/database/smoke.sqlite');
$run([PHP_BINARY, 'vendor/bin/testbench', 'migrate:fresh', '--force'], $connectorDir);

$serve = fn (array $command, string $cwd, array $env = []) => tap(new Process($command, $cwd, $env, timeout: null), function (Process $process) use (&$children): void {
    $process->start();
    $children[] = $process;
});
// Comme `artisan serve` : le routeur du framework se lance depuis le dossier public.
$serve([PHP_BINARY, '-S', 'localhost:8100', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], $identityDir.'/public', $identityEnv);
$serve([PHP_BINARY, 'artisan', 'queue:work', '--sleep=1', '--tries=3', '--max-time=600'], $identityDir, $identityEnv);
$serve([PHP_BINARY, 'vendor/bin/testbench', 'serve', '--host=127.0.0.1', '--port=8110'], $connectorDir);

$http = new Client(['base_uri' => IDENTITY_URL, 'cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false]);
$plain = new Client(['base_uri' => IDENTITY_URL, 'http_errors' => false]);
$workbench = new Client(['base_uri' => WORKBENCH_URL, 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);

$up = $waitFor(function () use ($plain, $workbench) {
    try {
        return $plain->get('/health/ready')->getStatusCode() === 200 && $workbench->get('/api/whoami')->getStatusCode() === 401 ? true : null;
    } catch (Throwable) {
        return null;
    }
});
step('Identity, worker et workbench démarrés', $up === true);

$metadata = json_decode((string) $plain->get('/.well-known/oauth-authorization-server')->getBody(), true);
step('issuer d\'Identity = issuer configuré du workbench', ($metadata['issuer'] ?? '') === IDENTITY_URL, (string) ($metadata['issuer'] ?? ''));

/** @return array<string, mixed> */
$api = function (string $path, ?string $token) use ($workbench): array {
    $response = $workbench->get($path, ['headers' => $token === null ? [] : ['Authorization' => 'Bearer '.$token]]);

    return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getBody(), true)];
};

// --- Personne : inscription, vérification de l'email, autorisation PKCE (comme une application produit).
$email = 'connector+'.bin2hex(random_bytes(4)).'@example.test';
$password = 'mot-de-passe-de-smoke-test';
$redirect = 'autodonuts://oauth/callback';

$page = (string) $http->get('/register')->getBody();
$http->post('/register', ['form_params' => [
    '_token' => field($page, '_token'), 'name' => 'Connecteur Smoke', 'email' => $email,
    'password' => $password, 'password_confirmation' => $password,
]]);

$link = $waitFor(function () use ($plain, $mailpit, $email) {
    $found = json_decode((string) $plain->get("{$mailpit}/api/v1/search", ['query' => ['query' => "to:{$email}"]])->getBody(), true);
    foreach ($found['messages'] ?? [] as $message) {
        $body = json_decode((string) $plain->get("{$mailpit}/api/v1/message/{$message['ID']}")->getBody(), true);
        if (preg_match('#https?://[^\s"<>]+/email/verify/[^\s"<>]+#', html_entity_decode($body['HTML'] ?? ''), $m)) {
            return $m[0];
        }
    }

    return null;
});
step('inscription et email de vérification reçu', $link !== null);
$http->get(preg_replace('#^https?://[^/]+#', IDENTITY_URL, $link));

/** @return array{access_token: string, refresh_token: string} */
$login = function (string $client = 'autodonuts-mobile', ?string $clientRedirect = null, string $scope = 'profile email autodonuts:access vehicles:read vehicles:write organizations:read') use ($http, $plain, $redirect): array {
    $clientRedirect ??= $redirect;
    $verifier = b64url(random_bytes(64));
    $state = b64url(random_bytes(12));
    $query = http_build_query([
        'client_id' => $client, 'redirect_uri' => $clientRedirect, 'response_type' => 'code',
        'scope' => $scope, 'state' => $state,
        'code_challenge' => b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
    ]);

    $response = $http->get('/oauth/authorize?'.$query);
    if ($response->getStatusCode() === 200) {
        $html = (string) $response->getBody();
        $response = $http->post('/oauth/authorize', ['form_params' => [
            '_token' => field($html, '_token'), 'state' => $state, 'client_id' => $client, 'auth_token' => field($html, 'auth_token'),
        ]]);
    }
    parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

    return json_decode((string) $plain->post('/oauth/token', ['form_params' => [
        'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => $clientRedirect,
        'code' => $callback['code'] ?? '', 'code_verifier' => $verifier,
    ]])->getBody(), true);
};

$tokens = $login();
step('tokens Identity obtenus (autorisation PKCE)', isset($tokens['access_token']));
$access = $tokens['access_token'];
$sub = json_decode((string) $plain->get('/userinfo', ['headers' => ['Authorization' => 'Bearer '.$access]])->getBody(), true)['sub'] ?? '';

echo "\nAuthentification par le connecteur\n";

$whoami = $api('/api/whoami', $access);
step('token valide : accepté sans appel à Identity (JWKS, émetteur, audience)', $whoami['status'] === 200 && ($whoami['json']['sub'] ?? null) === $sub, json_encode($whoami));
step('sans token : 401', $api('/api/whoami', null)['status'] === 401);
step('token tronqué : 401', $api('/api/whoami', substr($access, 0, -4).'AAAA')['status'] === 401);

$exchange = json_decode((string) $plain->post('/oauth/token', ['form_params' => [
    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange', 'client_id' => 'autodonuts-mobile',
    'subject_token' => $access, 'audience' => 'identity-api',
]])->getBody(), true);
step('token identity-api présenté à l\'API produit : 401 (audience)', $api('/api/whoami', $exchange['access_token'] ?? '')['status'] === 401);
$scope = $api('/api/needs-scope', $access);
step('scope manquant (vehicles:read absent du token produit) : 403', $scope['status'] === 403 && ($scope['json']['error'] ?? '') === 'insufficient_scope');

echo "\nProfil local\n";

$first = $api('/api/me', $access);
step('première requête : profil créé depuis /userinfo', $first['status'] === 200 && ($first['json']['identity_user_id'] ?? null) === $sub && ($first['json']['display_name'] ?? null) === 'Connecteur Smoke', json_encode($first));
$second = $api('/api/me', $access);
step('deuxième requête : même profil, pas de doublon', ($second['json']['id'] ?? null) === ($first['json']['id'] ?? 0));

echo "\nAPI Identity\n";

$empty = $api('/api/organizations', $access);
step('organisations : liste vide, via échange de token', $empty['status'] === 200 && $empty['json']['data'] === []);

$page = (string) $http->get('/account/organizations')->getBody();
$created = $http->post('/account/organizations', ['form_params' => ['_token' => field($page, '_token'), 'name' => 'Garage Connecteur '.bin2hex(random_bytes(2))]]);
$orgSlug = basename($created->getHeaderLine('Location'));
$organizations = $api('/api/organizations', $access);
$orgId = $organizations['json']['data'][0]['id'] ?? '';
step('organisation créée dans Identity : lue avec le rôle owner', count($organizations['json']['data'] ?? []) === 1 && $organizations['json']['data'][0]['role'] === 'owner' && $organizations['json']['data'][0]['slug'] === $orgSlug);
$detail = $api('/api/organizations/'.$orgId, $access);
step('détail : membres avec nom, sans email', ($detail['json']['data']['members'][0]['name'] ?? null) === 'Connecteur Smoke' && ! str_contains(json_encode($detail['json']), $email));
step('organisation inconnue ou d\'autrui : 404', $api('/api/organizations/01J0NOBODY000000000000000Z', $access)['status'] === 404);

$status = $api('/api/status/'.$sub, $access);
step('statut de compte en service à service (client_credentials)', $status['status'] === 200 && ($status['json']['exists'] ?? null) === true && ($status['json']['suspended'] ?? null) === false && array_key_exists('deletion', $status['json']) && $status['json']['deletion'] === null);
step('compte inconnu : exists = false', ($api('/api/status/01J0NOBODY000000000000000Z', $access)['json']['exists'] ?? true) === false);

echo "\nRotation de clé\n";

$run([PHP_BINARY, 'artisan', 'identity:keys:rotate'], $identityDir, $identityEnv);
$rotated = $login();
$claims = json_decode(base64_decode(strtr(explode('.', $rotated['access_token'])[0], '-_', '+/')), true);
$oldKid = json_decode(base64_decode(strtr(explode('.', $access)[0], '-_', '+/')), true)['kid'] ?? '';
step('nouveau token signé par une autre clé', ($claims['kid'] ?? '') !== '' && $claims['kid'] !== $oldKid, "{$oldKid} → {$claims['kid']}");
sleep(2);
step('le workbench suit la rotation sans redémarrage', $api('/api/whoami', $rotated['access_token'])['status'] === 200);
step('un token signé avant la rotation reste accepté', $api('/api/whoami', $access)['status'] === 200);
$access = $rotated['access_token'];

echo "\nVéhicules\n";

const VIN = 'VF3ABCDEFGH123456';
const PLATE = 'AB-123-CD';

/** @return array{status: int, json: array<string, mixed>|null, body: string, headers: array<string, list<string>>} */
$call = function (string $method, string $path, string $token, ?array $json = null) use ($workbench): array {
    $response = $workbench->request($method, $path, array_filter(['headers' => ['Authorization' => 'Bearer '.$token], 'json' => $json]));

    return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getBody(), true), 'body' => (string) $response->getBody(), 'headers' => $response->getHeaders()];
};
/** @return array{status: int, json: array<string, mixed>|null, body: string, headers: array<string, list<string>>} */
$identity = function (string $method, string $path, string $token, ?array $json = null) use ($plain): array {
    $response = $plain->request($method, $path, array_filter(['headers' => ['Authorization' => 'Bearer '.$token], 'json' => $json]));

    return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getBody(), true), 'body' => (string) $response->getBody(), 'headers' => $response->getHeaders()];
};

// AutoTrackly (deuxième produit, avec le scope explicite du groupe sensible) : appel direct à l'API d'Identity.
$tracklyTokens = $login('autotrackly-mobile', 'autotrackly://oauth/callback', 'profile email autotrackly:access vehicles:read vehicles:write vehicles:sensitive');
$tracklyIdentity = json_decode((string) $plain->post('/oauth/token', ['form_params' => [
    'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange', 'client_id' => 'autotrackly-mobile',
    'subject_token' => $tracklyTokens['access_token'], 'audience' => 'identity-api',
]])->getBody(), true)['access_token'] ?? '';
step('token identity-api d\'AutoTrackly avec vehicles:sensitive', str_contains((string) (json_decode(base64_decode(strtr(explode('.', $tracklyIdentity)[1] ?? '', '-_', '+/')), true)['scope'] ?? ''), 'vehicles:sensitive'));

$created = $identity('POST', '/api/v1/vehicles', $tracklyIdentity, [
    'identity' => ['make' => 'Peugeot', 'model' => '205', 'trim' => 'GTI', 'year' => 1991, 'fuel' => 'petrol'],
    'specs' => ['color' => 'Rouge'], 'usage' => ['mileage_km' => 182000, 'mileage_read_on' => date('Y-m-d')],
    'sensitive' => ['vin' => 'vf3 abcdefgh-123456', 'plate' => ' ab 123 cd '],
    'link' => ['groups' => ['identity', 'specs', 'usage', 'sensitive'], 'visibility' => 'private'],
]);
$vid = $created['json']['data']['id'] ?? '';
step('AutoTrackly crée un véhicule (VIN et plaque normalisés), sans jamais les renvoyer', $created['status'] === 201 && $vid !== '' && ! str_contains($created['body'], 'VF3') && ! str_contains($created['body'], 'AB-123'), (string) $created['status']);

$sensitive = $identity('GET', "/api/v1/vehicles/{$vid}?fields=identity,sensitive", $tracklyIdentity);
step('AutoTrackly lit le VIN sur demande explicite : jamais mis en cache', ($sensitive['json']['data']['sensitive']['vin'] ?? '') === VIN && str_contains($sensitive['headers']['Cache-Control'][0] ?? '', 'no-store'));

$link = $call('PUT', "/api/vehicles/{$vid}/link", $access, ['groups' => ['identity', 'specs', 'usage'], 'visibility' => 'public']);
step('AutoDonuts (le workbench) lie le même véhicule avec d\'autres groupes', $link['status'] === 200 && ($link['json']['data']['groups'] ?? []) === ['identity', 'specs', 'usage'], (string) $link['status']);

$owner = $call('GET', "/api/vehicles/{$vid}?fields=identity,specs,usage,sensitive", $access);
step('le propriétaire lit par AutoDonuts : ses groupes, jamais le VIN même demandé', $owner['status'] === 200 && array_keys($owner['json']['data']['groups'] ?? []) === ['identity', 'specs', 'usage'] && ! str_contains($owner['body'], 'VF3'));

$publicRead = $call('GET', "/api/public/vehicles?ids={$vid}&reader=anonymous", $access);
step('visiteur anonyme (service à service) : identité et specs seulement, pas le kilométrage', array_keys($publicRead['json']['data'][0]['groups'] ?? []) === ['identity', 'specs']);

$call('PUT', "/api/vehicles/{$vid}/link", $access, ['groups' => ['identity', 'specs', 'usage'], 'visibility' => 'public', 'share_usage' => true]);
$shared = $call('GET', "/api/public/vehicles?ids={$vid}&reader=anonymous", $access);
step('le propriétaire ouvre le kilométrage : il apparaît, jamais le VIN', array_keys($shared['json']['data'][0]['groups'] ?? []) === ['identity', 'specs', 'usage'] && ! str_contains($shared['body'], 'VF3') && ! str_contains($shared['body'], 'AB-123'));

$version = (int) ($owner['json']['data']['version'] ?? 1);
$updated = $call('PATCH', "/api/vehicles/{$vid}", $access, ['version' => $version, 'data' => ['specs' => ['color' => 'Bleu']]]);
step('modification avec la version lue', $updated['status'] === 200 && ($updated['json']['data']['version'] ?? 0) === $version + 1);
$stale = $call('PATCH', "/api/vehicles/{$vid}", $access, ['version' => $version, 'data' => ['specs' => ['color' => 'Vert']]]);
step('version périmée : refusée avec la version courante', $stale['status'] === 412 && ($stale['json']['current_version'] ?? 0) === $version + 1, json_encode($stale['json']));
$decrease = $call('PATCH', "/api/vehicles/{$vid}", $access, ['version' => $version + 1, 'data' => ['usage' => ['mileage_km' => 100]]]);
step('kilométrage en baisse : refusé sans confirmation', $decrease['status'] === 422 && ($decrease['json']['error'] ?? '') === 'mileage_decrease', json_encode($decrease['json']));

$plaintext = $tinker('echo json_encode(["leak" => collect(["vehicles", "vehicle_access_logs", "audit_logs", "webhook_deliveries"])->contains(fn ($t) => str_contains(json_encode(DB::table($t)->get()), "'.VIN.'") || str_contains(json_encode(DB::table($t)->get()), "'.PLATE.'"))]);');
step('en base : aucun VIN ni plaque en clair (fiches, journaux, audit, webhooks)', ($plaintext['leak'] ?? true) === false, json_encode($plaintext));

$call('POST', "/api/vehicles/{$vid}/notes", $access, ['note' => 'À vendre en 2027']);
$notes = fn () => $call('GET', "/api/vehicles/{$vid}/notes", $access)['json']['count'] ?? -1;
step('donnée locale d\'AutoDonuts rattachée au véhicule', $notes() === 1);
$deleted = $identity('DELETE', "/api/v1/vehicles/{$vid}", $tracklyIdentity);
step('AutoTrackly supprime le véhicule pour tous les produits', $deleted['status'] === 204);
$closed = $waitFor(fn () => $notes() === 0 ? true : null);
step('webhook vehicle.deleted : AutoDonuts ferme ses données locales', $closed === true);

// Deuxième véhicule, pour l'effacement du compte plus bas.
$second = $identity('POST', '/api/v1/vehicles', $tracklyIdentity, ['identity' => ['make' => 'Renault', 'model' => '5 Turbo']]);
$vid2 = $second['json']['data']['id'] ?? '';
$call('PUT', "/api/vehicles/{$vid2}/link", $access, ['groups' => ['identity'], 'visibility' => 'private']);
step('un second véhicule, lié à AutoDonuts', $second['status'] === 201 && $vid2 !== '');

echo "\nWebhooks : suspension et réactivation\n";

$suspend = $tinker('$user = App\Modules\Auth\Models\User::query()->findOrFail("'.$sub.'"); $actor = App\Modules\Auth\Models\User::factory()->create(); app(App\Modules\Auth\Actions\SuspendUser::class)->handle($user, $actor, "smoke connecteur"); echo json_encode(["actor" => $actor->id]);');
$blocked = $waitFor(fn () => $api('/api/me', $access)['status'] === 403 ? true : null);
step('webhook account.suspended reçu : profil bloqué sans attendre l\'expiration du token', $blocked === true);
step('le token, lui, reste valide localement (aucun contrôle de révocation)', $api('/api/whoami', $access)['status'] === 200);
$suspended = $api('/api/status/'.$sub, $access);
step('réconciliation : le statut lu à Identity dit « suspendu »', ($suspended['json']['suspended'] ?? false) === true);
$delivery = $tinker('echo json_encode(App\Webhooks\Models\WebhookDelivery::query()->where("event_type", "account.suspended")->latest("id")->first(["status", "attempts", "endpoint"])->toArray());');
step('livraison tracée côté Identity', ($delivery['status'] ?? '') === 'delivered' && ($delivery['endpoint'] ?? '') === 'autodonuts', json_encode($delivery));

$tinker('$user = App\Modules\Auth\Models\User::query()->findOrFail("'.$sub.'"); $actor = App\Modules\Auth\Models\User::query()->findOrFail("'.$suspend['actor'].'"); app(App\Modules\Auth\Actions\ReinstateUser::class)->handle($user, $actor); echo json_encode(["ok" => true]);');
$restored = $waitFor(fn () => $api('/api/me', $access)['status'] === 200 ? true : null);
step('webhook account.reinstated reçu : profil rétabli', $restored === true);

echo "\nSuppression de compte\n";

$signIn = function () use ($http, $email, $password): int {
    $page = (string) $http->get('/login')->getBody();

    return $http->post('/login', ['form_params' => ['_token' => field($page, '_token'), 'email' => $email, 'password' => $password]])->getStatusCode();
};
$requestDeletion = function () use ($http, $email, $password): int {
    $page = (string) $http->get('/account/delete')->getBody();

    return $http->post('/account/delete', ['form_params' => ['_token' => field($page, '_token'), 'password' => $password, 'email_confirmation' => $email]])->getStatusCode();
};
$deletionStatus = function () use ($api, $sub, $access) {
    $response = $api('/api/status/'.$sub, $access);

    return $response['status'] === 200 ? ($response['json']['deletion'] ?? null) : 'HTTP '.$response['status'].' '.json_encode($response['json']);
};

step('reconnexion après la réactivation', $signIn() === 302);
$inventory = (string) $http->get('/account/delete')->getBody();
step('page de suppression : inventaire avec l\'organisation à membre unique', str_contains($inventory, 'sera supprimée') && str_contains($inventory, 'Garage Connecteur'));

step('suppression demandée : déconnecté, redirigé vers la connexion', $requestDeletion() === 302 && str_contains($http->get('/account/profile')->getHeaderLine('Location'), '/login'));
$locked = $waitFor(fn () => $api('/api/me', $access)['status'] === 403 ? true : null);
step('webhook account.deletion_requested : profil verrouillé côté produit', $locked === true);
step('statut lu à Identity : suppression en attente', $deletionStatus() === 'pending', (string) $deletionStatus());

step('reconnexion pendant le délai', $signIn() === 302);
$unlocked = $waitFor(fn () => $api('/api/me', $access)['status'] === 200 ? true : null);
step('webhook account.deletion_cancelled : la reconnexion annule, profil déverrouillé', $unlocked === true && $deletionStatus() === null);

step('nouvelle demande de suppression', $requestDeletion() === 302);
$waitFor(fn () => $api('/api/me', $access)['status'] === 403 ? true : null);
$tinker('App\Modules\Auth\Models\AccountDeletion::query()->where("user_id", "'.$sub.'")->where("status", "pending")->update(["due_at" => now()->subMinute()]); echo json_encode(["ok" => true]);');
$run([PHP_BINARY, 'artisan', 'identity:accounts:process-deletions'], $identityDir, $identityEnv);
$acknowledged = $waitFor(function () use ($tinker, $sub) {
    $ack = $tinker('echo json_encode(App\Modules\Auth\Models\AccountDeletionAcknowledgement::query()->whereIn("deletion_id", App\Modules\Auth\Models\AccountDeletion::query()->where("user_id", "'.$sub.'")->select("id"))->pluck("outcome", "product")->all());');

    return ($ack['autodonuts'] ?? null) === 'acknowledged' ? $ack : null;
}, 40);
step('webhook account.deletion_due : le produit efface son profil et accuse à Identity', $acknowledged !== null, json_encode($acknowledged));

$run([PHP_BINARY, 'artisan', 'identity:accounts:process-deletions'], $identityDir, $identityEnv);
$gone = $tinker('echo json_encode(["user" => App\Modules\Auth\Models\User::query()->whereKey("'.$sub.'")->exists(), "org" => App\Modules\Organizations\Models\Organization::query()->where("slug", "'.$orgSlug.'")->exists(), "proof" => App\Modules\Auth\Models\AccountDeletion::query()->where("user_id", "'.$sub.'")->where("status", "completed")->exists()]);');
step('tous les accusés reçus : compte et organisation à membre unique effacés, preuve conservée', $gone === ['user' => false, 'org' => false, 'proof' => true], json_encode($gone));
step('statut : le compte n\'existe plus', ($api('/api/status/'.$sub, $access)['json']['exists'] ?? true) === false);
$signIn();
$vehicleGone = $tinker('echo json_encode(["vehicle" => App\\Modules\\Vehicles\\Models\\Vehicle::query()->whereKey("'.$vid2.'")->exists(), "webhook" => App\\Webhooks\\Models\\WebhookDelivery::query()->where("event_type", "vehicle.deleted")->where("payload", "like", "%'.$vid2.'%")->where("status", "delivered")->exists()]);');
step('les véhicules du compte sont effacés avec lui, et AutoDonuts en est prévenu', $vehicleGone === ['vehicle' => false, 'webhook' => true], json_encode($vehicleGone));
$signIn();
step('la connexion avec l\'ancienne adresse ne mène plus à aucun compte', str_contains($http->get('/account/profile')->getHeaderLine('Location'), '/login'));

echo "\nTout est vert.\n";
