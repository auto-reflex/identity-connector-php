<?php

namespace AutoReflex\IdentityConnector\Testing;

/**
 * Pour les tests d'une API produit : `$this->fakeIdentity('autotrackly-api')` remplace Identity.
 *
 *     $identity = $this->fakeIdentity('autotrackly-api')->user($id, email: 'camille@example.test');
 *     $this->getJson('/api/me', ['Authorization' => 'Bearer '.$identity->tokenFor($id)])->assertOk();
 */
trait FakesIdentity
{
    protected function fakeIdentity(string $audience, string $issuer = 'https://identity.test'): FakeIdentity
    {
        return FakeIdentity::install($audience, $issuer);
    }
}
