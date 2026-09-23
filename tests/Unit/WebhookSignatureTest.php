<?php

use AutoGteck\IdentityConnector\Webhooks\WebhookSignature;

// Même vecteur que la suite d'Identity (tests/Feature/Webhooks/WebhookDeliveryTest.php) : l'empreinte du corps signée dans le JWT.
const VECTOR_BODY = '{"id":"01J0EVENT0000000000000000A","type":"account.suspended"}';

it('computes the body digest as unpadded base64url SHA-256, like Identity', function () {
    expect(WebhookSignature::digest(VECTOR_BODY))->toBe(rtrim(strtr(base64_encode(hash('sha256', VECTOR_BODY, true)), '+/', '-_'), '='))
        ->not->toContain('=')->not->toContain('+')->not->toContain('/');
});

it('names the header and the token type Identity uses', function () {
    expect(WebhookSignature::HEADER)->toBe('Identity-Signature')->and(WebhookSignature::TYPE)->toBe('identity-webhook+jwt');
});
