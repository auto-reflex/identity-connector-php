<?php

use AutoGteck\IdentityConnector\Jwt\JwtVerifier;
use AutoGteck\IdentityConnector\Web\WebLogin;
use AutoGteck\IdentityConnector\Web\WebLoginClient;
use AutoGteck\IdentityConnector\Web\WebSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

const WEB_USER = '01J0USER00000000000000000A';

/** Le paramètre d'une adresse de redirection. */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('beacon-api')->user(WEB_USER, 'Camille Durand');

    /** Joue le parcours complet : le produit envoie vers Identity, la personne accepte, le produit reçoit le code. */
    $this->logIn = function (?array $roles = null) {
        $location = $this->get('/admin/auth/redirect')->assertRedirect()->headers->get('Location');

        return $this->get($this->identity->web()->approve($location, WEB_USER, $roles));
    };
});

afterEach(fn () => Carbon::setTestNow());

it('sends a visitor to Identity with PKCE S256, a state, the product scope and the exact callback address', function () {
    $location = $this->get('/admin/auth/redirect')->assertRedirect()->headers->get('Location');
    $query = queryOf($location);

    expect($location)->toStartWith('https://identity.test/oauth/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'test-web-client',
            'redirect_uri' => 'http://localhost/admin/auth/callback',
            'response_type' => 'code',
            'scope' => 'profile test:access',
            'code_challenge_method' => 'S256',
        ])
        ->and($query)->not->toHaveKey('prompt')
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32);

    $pending = session(WebLogin::LOGIN_KEY);
    $expected = rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=');

    expect($query['code_challenge'])->toBe($expected)->and($query['state'])->toBe($pending['state']);
});

it('protects a page: a visitor is sent to the login page of the application, then back once connected', function () {
    $this->get('/admin/dashboard')->assertRedirect('http://localhost/admin/login');

    $this->logIn->call($this)->assertRedirect('http://localhost/admin/dashboard');

    $this->getJson('/admin/dashboard')->assertOk()->assertJson(['name' => 'Camille Durand', 'roles' => ['admin'], 'has_admin' => true, 'token_subject' => WEB_USER]);
});

it('answers 401 to a JSON request without a connection', function () {
    $this->getJson('/admin/dashboard')->assertUnauthorized()->assertJson(['error' => 'unauthenticated']);
});

it('starts a new session at login and keeps the tokens out of the session, encrypted in the cache', function () {
    $this->get('/admin/dashboard');
    $before = session()->getId();

    $this->logIn->call($this)->assertRedirect();

    $id = session(WebSession::SESSION_KEY);
    $stored = Cache::get('identity-connector:web:'.$id);
    $connection = app(WebSession::class)->current(app('session.store'));

    expect(session()->getId())->not->toBe($before)
        ->and($id)->toBeString()
        ->and($connection?->accessToken)->toStartWith('eyJ')
        ->and(json_encode(app('session.store')->all()))->not->toContain($connection->accessToken)->and(json_encode(app('session.store')->all()))->not->toContain($connection->refreshToken)
        ->and($stored)->toBeString()
        ->and($stored)->not->toContain($connection->accessToken)->and($stored)->not->toContain($connection->refreshToken);
});

it('refuses a callback whose state does not match, and one that replays a consumed state', function () {
    $location = $this->get('/admin/auth/redirect')->headers->get('Location');
    $back = $this->identity->web()->approve($location, WEB_USER);

    $this->get(str_replace('state='.queryOf($back)['state'], 'state=forged', $back))->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertJson(['error' => 'invalid_state']);
    $this->getJson('/admin/dashboard')->assertUnauthorized();

    // L'état a été consommé par la première tentative : rejouer le retour légitime ne connecte pas.
    $this->get($back)->assertRedirect('http://localhost/admin/login');
    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('refuses a callback that arrives without a login in progress', function () {
    $this->get('/admin/auth/callback?code=abc&state=xyz')->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertJson(['error' => 'invalid_state']);
});

it('reports a refusal from the person as access_denied', function () {
    $location = $this->get('/admin/auth/redirect')->headers->get('Location');
    $state = queryOf($location)['state'];

    $this->get('/admin/auth/callback?error=access_denied&state='.$state)->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertJson(['error' => 'access_denied']);
});

it('refuses a token that lacks the product scope, and revokes it', function () {
    $location = $this->get('/admin/auth/redirect')->headers->get('Location');
    $back = $this->identity->web()->approve(str_replace('profile+test%3Aaccess', 'profile', str_replace('profile%20test%3Aaccess', 'profile', $location)), WEB_USER);

    $this->get($back)->assertRedirect('http://localhost/admin/login');

    $this->get('/admin/login')->assertJson(['error' => 'insufficient_scope']);
    expect($this->identity->web()->revokedTokens())->toHaveCount(1);
    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('answers 403 without the required role, but lets a connected person reach a page with no role requirement', function () {
    $this->logIn->call($this, [])->assertRedirect();

    $this->get('/admin/dashboard')->assertForbidden();
    $this->getJson('/admin/anyone')->assertOk()->assertJson(['id' => WEB_USER]);
});

it('renews the connection near the end of the access token and keeps the person connected', function () {
    $this->logIn->call($this)->assertRedirect();
    expect($this->identity->web()->refreshCalls())->toBe(0);

    Carbon::setTestNow('2026-09-20 10:14:30');   // le token d'accès (15 min) approche de son échéance

    $this->getJson('/admin/dashboard')->assertOk();
    expect($this->identity->web()->refreshCalls())->toBe(1);

    // Le nouveau token est valable 15 minutes de plus : pas de nouveau refresh tout de suite.
    Carbon::setTestNow('2026-09-20 10:20:00');
    $this->getJson('/admin/dashboard')->assertOk();
    expect($this->identity->web()->refreshCalls())->toBe(1);
});

it('renews only once when several requests find the connection due together', function () {
    $this->logIn->call($this)->assertRedirect();
    Carbon::setTestNow('2026-09-20 10:14:30');

    $sessions = app(WebSession::class);
    $login = app(WebLogin::class);
    $stale = $sessions->current(app('session.store'));

    $first = $sessions->renew($stale, $login->renew(...));
    // La seconde requête part de la même copie périmée : elle trouve la connexion déjà renouvelée dans le verrou.
    $second = $sessions->renew($stale, $login->renew(...));

    expect($this->identity->web()->refreshCalls())->toBe(1)
        ->and($second?->refreshToken)->toBe($first?->refreshToken)
        ->and($second?->refreshToken)->not->toBe($stale->refreshToken);
});

it('takes a removed role away at the next renewal', function () {
    $this->logIn->call($this)->assertRedirect();
    $this->getJson('/admin/dashboard')->assertOk();

    $this->identity->web()->roles(WEB_USER, []);
    Carbon::setTestNow('2026-09-20 10:14:30');

    $this->get('/admin/dashboard')->assertForbidden();
});

it('closes the connection when Identity refuses the renewal, as for a suspended account', function () {
    $this->logIn->call($this)->assertRedirect();
    $this->identity->revoke(WEB_USER);
    Carbon::setTestNow('2026-09-20 10:14:30');

    $this->get('/admin/dashboard')->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertJson(['error' => 'session_expired']);
    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('keeps a connection alive through an Identity outage until its token expires, then closes it', function () {
    $this->logIn->call($this)->assertRedirect();
    $this->identity->goDown();

    Carbon::setTestNow('2026-09-20 10:14:30');
    $this->getJson('/admin/dashboard')->assertOk();

    Carbon::setTestNow('2026-09-20 10:16:00');
    $this->get('/admin/dashboard')->assertRedirect('http://localhost/admin/login');
    $this->get('/admin/login')->assertJson(['error' => 'unavailable']);

    $this->identity->comeBack();
    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('logs out: revokes the token, forgets the connection, and makes the next login ask for the password again', function () {
    $this->logIn->call($this)->assertRedirect();
    $id = session(WebSession::SESSION_KEY);

    $this->post('/admin/auth/logout')->assertRedirect('http://localhost/admin/login');

    expect($this->identity->web()->revokedTokens())->toHaveCount(1)
        ->and(Cache::get('identity-connector:web:'.$id))->toBeNull();
    $this->getJson('/admin/dashboard')->assertUnauthorized();

    $location = $this->get('/admin/auth/redirect')->headers->get('Location');
    expect(queryOf($location)['prompt'])->toBe('login');

    // Une seule fois : la connexion suivante n'a plus à le redemander.
    $this->identity->web()->approve($location, WEB_USER);
    expect(queryOf($this->get('/admin/auth/redirect')->headers->get('Location')))->not->toHaveKey('prompt');
});

it('still logs out when Identity cannot revoke the token', function () {
    $this->logIn->call($this)->assertRedirect();
    $this->identity->goDown();

    $this->post('/admin/auth/logout')->assertRedirect('http://localhost/admin/login');
    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('treats a tampered or unreadable stored connection as no connection', function () {
    $this->logIn->call($this)->assertRedirect();
    Cache::put('identity-connector:web:'.session(WebSession::SESSION_KEY), 'not-encrypted-at-all', 600);

    $this->getJson('/admin/dashboard')->assertUnauthorized();
});

it('sends someone who is already connected straight home instead of back to Identity', function () {
    $this->logIn->call($this)->assertRedirect();

    $this->get('/admin/auth/redirect')->assertRedirect('http://localhost/admin/dashboard');
});

it('does nothing and asks for nothing until the web login is configured', function () {
    config(['identity-connector.web.client_id' => null]);
    app()->forgetInstance(WebLoginClient::class);

    expect(fn () => app(WebLoginClient::class))->toThrow(InvalidArgumentException::class, 'IDENTITY_WEB_CLIENT_ID');
});

it('verifies the token of a connection with the keys of Identity, like an API would', function () {
    $id = $this->identity->web()->signIn(WEB_USER, ['admin']);
    $connection = app(WebSession::class)->current(tap(app('session.store'))->put(WebSession::SESSION_KEY, $id));

    $verified = app(JwtVerifier::class)->verify($connection->accessToken);

    expect($verified->subject)->toBe(WEB_USER)->and($verified->hasScope('test:access'))->toBeTrue()->and($verified->roles)->toBe(['admin']);
});
