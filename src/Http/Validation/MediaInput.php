<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation;

use DomainException;

/** Pure validation and GEDCOM helpers shared by the REST and MCP transports. */
final class MediaInput
{
    /** Keep binary data out of the model context; use multipart REST above this size. */
    public const int MCP_INLINE_LIMIT = 512 * 1024;
    /** Retained as a compatibility alias for clients that inspect the old constant. */
    public const int MCP_LIMIT = self::MCP_INLINE_LIMIT;
    public const int REST_LIMIT = 20 * 1024 * 1024;
    private const array TYPES = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp']];

    public static function filename(string $name): string
    {
        if ($name === '' || strlen($name) > 180 || str_contains($name, '..') || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $name)) {
            throw new DomainException('filename must be a basename without paths or dot-dot.', 400);
        }
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        if ($name[0] === '.') {
            throw new DomainException('Hidden filenames are not allowed.', 400);
        }
        return $name;
    }

    public static function image(string $bytes, string $name, int $limit): string
    {
        if ($bytes === '' || strlen($bytes) > $limit) {
            throw new DomainException('Empty image or upload size limit exceeded.', 413);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        if (!isset(self::TYPES[$mime]) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TYPES[$mime], true) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new DomainException('File content and extension must match a JPEG, PNG, GIF or WebP image. PDF and SVG are not supported.', 415);
        }
        if ($info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > 40000000) {
            throw new DomainException('Image exceeds the 40 megapixel limit.', 413);
        }
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        if ($memoryLimit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 8 + strlen($bytes) * 2 > $memoryLimit) {
            throw new DomainException('Image needs more decoding memory than this server allows.', 413);
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new DomainException('Image data cannot be decoded.', 415);
        }
        unset($decoded);
        return $mime;
    }

    public static function base64(string $encoded): string
    {
        if (strlen($encoded) > self::maxBase64Length()) {
            throw new DomainException('Inline MCP images are limited to 512 KiB decoded. Use multipart REST POST /api/media for larger files.', 413);
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded) {
            throw new DomainException('content-base64 must be canonical base64, without a data URL prefix or whitespace.', 400);
        }
        if (strlen($bytes) > self::MCP_INLINE_LIMIT) {
            throw new DomainException('Inline MCP images are limited to 512 KiB decoded. Use multipart REST POST /api/media for larger files.', 413);
        }
        return $bytes;
    }

    public static function maxBase64Length(): int
    {
        return 4 * (int) ceil(self::MCP_INLINE_LIMIT / 3);
    }

    public static function path(string $path): string
    {
        if ($path === '' || preg_match('~[\\\\:\x00-\x1f\x7f]~', $path) || str_starts_with($path, '/')) {
            throw new DomainException('Unsafe media path.', 400);
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new DomainException('Unsafe media path.', 400);
            }
        }
        return $path;
    }

    public static function text(array $input, string $key, string $default = ''): string
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $default;
        if (!is_string($value) || strlen($value) > 16384 || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new DomainException('Invalid text field: ' . $key, 400);
        }
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    public static function field(int $level, string $tag, string $value): string
    {
        return $level . ' ' . $tag . ' ' . str_replace("\n", "\n" . ($level + 1) . ' CONT ', $value);
    }

    /** Replace one subtree, retaining unrelated facts; refuse ambiguous multiple values. */
    public static function replace(string $gedcom, int $level, string $tag, string $value): string
    {
        $pattern = '/\n' . $level . ' ' . preg_quote($tag, '/') . '(?: [^\n]*)?(?:\n[' . ($level + 1) . '-9] [^\n]*)*/';
        if (preg_match_all($pattern, $gedcom) > 1) {
            throw new DomainException('Multiple ' . $tag . ' fields: edit this record in webtrees.', 409);
        }
        return preg_replace($pattern, '', $gedcom) . ($value === '' ? '' : "\n" . self::field($level, $tag, $value));
    }
}
