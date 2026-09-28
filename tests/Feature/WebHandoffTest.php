<?php

use AutoGteck\IdentityConnector\Web\WebLogin;
use AutoGteck\IdentityConnector\Web\WebLoginClient;
use Carbon\Carbon;

const HANDOFF_USER = '01J0USER00000000000000000H';

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 10:00:00');
    $this->identity = $this->fakeIdentity('beacon-api')->user(HANDOFF_USER, 'Camille Durand', 'camille@example.test');
});

afterEach(fn () => Carbon::setTestNow());

it('opens a connection from a handoff code and lands on the requested page of the application (AR-096)', function () {
    $code = $this->identity->web()->handoff(HANDOFF_USER);

    $this->get('/admin/auth/handoff?'.http_build_query(['code' => $code, 'next' => '/admin/anyone?product=map']))
        ->assertRedirect('http://localhost/admin/anyone?product=map');

    $this->getJson('/admin/anyone')->assertOk()->assertJson(['id' => HANDOFF_USER, 'email' => 'camille@example.test']);
});

it('never sends the person to another site, whatever `next` says', function (string $next) {
    $code = $this->identity->web()->handoff(HANDOFF_USER);

    $this->get('/admin/auth/handoff?'.http_build_query(['code' => $code, 'next' => $next]))
        ->assertRedirect('http://localhost/admin/dashboard');
})->with(['https://evil.test/', '//evil.test/x', '/\\evil.test', 'javascript:alert(1)']);

it('refuses a code already used, and says so on the login page', function () {
    $code = $this->identity->web()->handoff(HANDOFF_USER);

    $this->get('/admin/auth/handoff?code='.$code)->assertRedirect();
    $this->flushSession();

    $this->get('/admin/auth/handoff?code='.$code)
        ->assertRedirect('http://localhost/admin/login')
        ->assertSessionHas(WebLogin::ERROR_KEY, 'failed');

    $this->getJson('/admin/anyone')->assertUnauthorized();
});

it('replaces a connection already open in the browser by the person of the code', function () {
    $this->actingAsWebIdentity($this->identity, '01J0USER00000000000000000Z', 'Autre Personne');
    $code = $this->identity->web()->handoff(HANDOFF_USER);

    $this->get('/admin/auth/handoff?code='.$code)->assertRedirect();

    $this->getJson('/admin/anyone')->assertJsonPath('id', HANDOFF_USER);
});

it('says Identity is unavailable when it cannot exchange the code', function () {
    $code = $this->identity->web()->handoff(HANDOFF_USER);
    $this->identity->goDown();

    $this->get('/admin/auth/handoff?code='.$code)->assertSessionHas(WebLogin::ERROR_KEY, 'unavailable');
});

it('asks for the extra scopes of the application, such as the email', function () {
    config(['identity-connector.web.extra_scopes' => ['email']]);
    app()->forgetInstance(WebLoginClient::class);

    $location = $this->get('/admin/auth/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('profile email test:access');
});
