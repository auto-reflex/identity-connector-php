<?php

namespace AutoReflex\IdentityConnector\Client;

use AutoReflex\IdentityConnector\Profiles\IdentityUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Client de l'API d'Identity (AR-052) : timeouts courts, une reprise sur les lectures, erreurs typées.
 * Aucun token n'est jamais écrit dans un log ni dans un message d'exception.
 */
class IdentityClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly int $timeout = 3,
        private readonly int $connectTimeout = 2,
    ) {}

    /**
     * Ce qu'Identity dit de la personne du token produit (`/userinfo`, AR-048).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function userInfo(string $accessToken): IdentityUser
    {
        $claims = $this->get($accessToken, '/userinfo')->json();

        if (! is_array($claims) || ! isset($claims['sub']) || ! is_string($claims['sub'])) {
            throw new IdentityUnavailable('Identity returned an unusable /userinfo response.');
        }

        return IdentityUser::fromUserInfo($claims);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    protected function get(string $accessToken, string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->withToken($accessToken)->get($this->url($path), $query), retry: true);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    protected function send(callable $call, bool $retry): Response
    {
        $attempts = $retry ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $call($this->pending());
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new IdentityUnavailable('Identity is unreachable.');
            }

            if ($response->serverError() || $response->status() === 429) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new IdentityUnavailable("Identity answered HTTP {$response->status()}.");
            }

            if ($response->failed()) {
                throw new IdentityRejected($response->status(), error: is_string($response->json('error')) ? $response->json('error') : null);
            }

            return $response;
        }

        throw new IdentityUnavailable('Identity is unreachable.');
    }

    private function pending(): PendingRequest
    {
        return $this->http->timeout($this->timeout)->connectTimeout($this->connectTimeout)->withoutRedirecting()->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
