<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation;

use DomainException;
use Fisharebest\Webtrees\Registry;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Throwable;

/**
 * Short-lived, HMAC-signed capability tokens for media content, preview and upload.
 *
 * The token is self-authorizing: it was minted only after the caller passed the
 * normal scope, record and privacy checks. It therefore lets an MCP client open
 * a binary URL without routing bytes or base64 through the model context.
 */
final class MediaToken
{
    public const int TTL = 300;

    /** Mirrors WebtreesApi::PREF_ENCRYPTION_KEY without loading the module class. */
    private const string PREF_ENCRYPTION_KEY = 'encryption_key';

    /** Derive a dedicated signing key from the module's stored encryption key. */
    public static function key(string $encryptionKey): string
    {
        return hash('sha256', 'webtrees-media-capability:' . $encryptionKey);
    }

    /** Resolve the signing key from the module container, or '' when unavailable. */
    public static function moduleKey(): string
    {
        try {
            $module = Registry::container()->get(WebtreesApi::class);
            $encryption_key = (string) $module->getPreference(self::PREF_ENCRYPTION_KEY, '');
        } catch (Throwable) {
            return '';
        }
        return $encryption_key === '' ? '' : self::key($encryption_key);
    }

    /** @param array<string,scalar> $claims */
    public static function sign(array $claims, string $key, int $ttl = self::TTL): string
    {
        if ($key === '') {
            throw new DomainException('Media capability signing is not configured.', 500);
        }
        $claims['exp'] = time() + $ttl;
        $claims['nonce'] = bin2hex(random_bytes(8));
        $payload = self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $key, true));
    }

    /** @return array<string,mixed> */
    public static function verify(string $token, string $key, string $op): array
    {
        if ($key === '' || $token === '') {
            throw new DomainException('Invalid media token.', 403);
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new DomainException('Invalid media token.', 403);
        }
        [$payload, $signature] = $parts;
        if (!hash_equals(self::encode(hash_hmac('sha256', $payload, $key, true)), $signature)) {
            throw new DomainException('Invalid media token.', 403);
        }
        $claims = json_decode(self::decode($payload), true);
        if (!is_array($claims) || ($claims['op'] ?? null) !== $op) {
            throw new DomainException('Invalid media token.', 403);
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < time()) {
            throw new DomainException('Media token expired.', 403);
        }
        return $claims;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false) {
            throw new DomainException('Invalid media token.', 403);
        }
        return $raw;
    }
}
