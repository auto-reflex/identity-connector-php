<?php

namespace AutoGteck\IdentityConnector\Webhooks;

use Carbon\Carbon;

/**
 * Signature des webhooks d'Identity (AR-053) : `Identity-Signature: t=<unix>,v1=<hmac>`, HMAC-SHA256 de
 * `"{t}.{corps brut}"`. Plusieurs `v1` et plusieurs secrets sont acceptés, pour les rotations.
 */
final class WebhookSignature
{
    public const HEADER = 'Identity-Signature';

    public static function header(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /**
     * @param  list<string>  $secrets
     */
    public static function verify(?string $header, string $body, array $secrets, int $toleranceSeconds): bool
    {
        if ($header === null || $secrets === []) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(Carbon::now()->getTimestamp() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
