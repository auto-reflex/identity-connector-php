<?php

use AutoReflex\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;

const VECTOR_SECRET = 'whsec_0123456789abcdef0123456789abcdef';
const VECTOR_BODY = '{"id":"01J0EVENT0000000000000000A","type":"account.suspended"}';
const VECTOR_HEADER = 't=1790000000,v1=d04ec0920517fcdde75e04bcad81ce4c178bd466745c00c0cf9a643ca3a190d1';

beforeEach(fn () => Carbon::setTestNow(Carbon::createFromTimestampUTC(1790000000)));

afterEach(fn () => Carbon::setTestNow());

it('produces the reference vector shared with Identity (same secret, timestamp and body)', function () {
    expect(WebhookSignature::header(VECTOR_BODY, VECTOR_SECRET, 1790000000))->toBe(VECTOR_HEADER);
});

it('verifies the reference vector', function () {
    expect(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY, [VECTOR_SECRET], 300))->toBeTrue();
});

it('rejects a wrong secret, a changed body, and a missing or empty header', function () {
    expect(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY, ['another-secret-0123456789abcdefghijk'], 300))->toBeFalse()
        ->and(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY.' ', [VECTOR_SECRET], 300))->toBeFalse()
        ->and(WebhookSignature::verify(null, VECTOR_BODY, [VECTOR_SECRET], 300))->toBeFalse()
        ->and(WebhookSignature::verify('', VECTOR_BODY, [VECTOR_SECRET], 300))->toBeFalse()
        ->and(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY, [], 300))->toBeFalse();
});

it('signs the timestamp: moving it invalidates the signature', function () {
    $forged = str_replace('t=1790000000', 't=1790000001', VECTOR_HEADER);

    expect(WebhookSignature::verify($forged, VECTOR_BODY, [VECTOR_SECRET], 300))->toBeFalse();
});

it('enforces the tolerance in both directions', function (int $skew, bool $accepted) {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1790000000 + $skew));

    expect(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY, [VECTOR_SECRET], 300))->toBe($accepted);
})->with([[0, true], [300, true], [301, false], [-300, true], [-301, false], [86400, false]]);

it('accepts any of several secrets and any of several signatures', function () {
    $two = VECTOR_HEADER.',v1='.str_repeat('0', 64);

    expect(WebhookSignature::verify(VECTOR_HEADER, VECTOR_BODY, ['old-secret-0123456789abcdefghijklmn', VECTOR_SECRET], 300))->toBeTrue()
        ->and(WebhookSignature::verify($two, VECTOR_BODY, [VECTOR_SECRET], 300))->toBeTrue()
        ->and(WebhookSignature::verify('v1='.str_repeat('0', 64).',t=1790000000', VECTOR_BODY, [VECTOR_SECRET], 300))->toBeFalse();
});

it('rejects malformed headers', function (string $header) {
    expect(WebhookSignature::verify($header, VECTOR_BODY, [VECTOR_SECRET], 300))->toBeFalse();
})->with(['t=abc,v1=00', 'v1=d04ec0920517fcdde75e04bcad81ce4c178bd466745c00c0cf9a643ca3a190d1', 't=1790000000', 't=1790000000,v1=', 'garbage', 't=-1790000000,v1=00', 't=1790000000;v1=00']);
