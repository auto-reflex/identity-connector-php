<?php

namespace AutoGteck\IdentityConnector\Testing;

use AutoGteck\IdentityConnector\Web\WebSession;

/**
 * Pour les tests d'une API produit : `$this->fakeIdentity('autotrackly-api')` remplace Identity.
 *
 *     $identity = $this->fakeIdentity('autotrackly-api')->user($id, email: 'camille@example.test');
 *     $this->getJson('/api/me', ['Authorization' => 'Bearer '.$identity->tokenFor($id)])->assertOk();
 */
trait FakesIdentity
{
    /**
     * Ouvre une connexion web (AR-072) pour la personne : les requêtes suivantes du test passent `identity.web` comme si elle
     * s'était connectée. `$roles` : ses rôles d'équipe dans le produit.
     *
     * @param  list<string>  $roles
     */
    protected function actingAsWebIdentity(FakeIdentity $identity, string $userId, string $name = 'Camille Durand', array $roles = ['admin']): static
    {
        return $this->withSession([WebSession::SESSION_KEY => $identity->web()->signIn($userId, $roles, $name)]);
    }

    protected function fakeIdentity(string $audience, string $issuer = 'https://identity.test'): FakeIdentity
    {
        return FakeIdentity::install($audience, $issuer);
    }
}
