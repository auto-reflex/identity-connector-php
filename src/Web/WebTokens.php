<?php

namespace AutoGteck\IdentityConnector\Web;

/**
 * Les deux jetons rendus par Identity à la fin d'un échange de code ou d'un refresh (AR-072).
 */
final readonly class WebTokens
{
    public function __construct(public string $accessToken, public string $refreshToken) {}
}
