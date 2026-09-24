<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

final class SourceRecords
{
    /** @return array{gedcom:string,changed:bool} */
    public static function modify(string $gedcom, array $input): array
    {
        $changed = false;
        foreach (['title' => 'TITL', 'author' => 'AUTH', 'publication' => 'PUBL'] as $key => $tag) {
            if (!array_key_exists($key, $input)) continue;
            $value = self::text($input[$key], $key);
            $next = self::replace($gedcom, 1, $tag, $value);
            $changed = $changed || $next !== $gedcom;
            $gedcom = $next;
        }
        if (array_key_exists('note', $input)) {
            $value = self::text($input['note'], 'note');
            $next = self::replace($gedcom, 1, 'NOTE', $value);
            $changed = $changed || $next !== $gedcom;
            $gedcom = $next;
        }
        return ['gedcom' => trim($gedcom), 'changed' => $changed];
    }

    public static function addCitation(string $gedcom, string $sourceXref, string $event, string $page, string $note): array
    {
        $needle = '@' . $sourceXref . '@';
        $level = $event === '' ? 1 : 2;
        $prefix = $level . ' SOUR ' . $needle;
        if ($event === '') {
            if (preg_match('/^1 SOUR ' . preg_quote($needle, '/') . '(?:$|\s)/m', $gedcom) === 1) return ['gedcom' => $gedcom, 'changed' => false];
            $citation = $prefix;
            if ($page !== '') $citation .= "\n2 PAGE " . $page;
            if ($note !== '') $citation .= "\n2 DATA\n3 TEXT " . str_replace("\n", "\n3 CONT ", $note);
            return ['gedcom' => trim($gedcom . "\n" . $citation), 'changed' => true];
        }
        $pattern = '/^1 ' . preg_quote($event, '/') . '(?:\n(?!1 )[^\n]*)*/m';
        if (preg_match($pattern, $gedcom, $match) !== 1) throw new \DomainException('Event not found in target record.', 404);
        if (preg_match('/^2 SOUR ' . preg_quote($needle, '/') . '(?:$|\s)/m', $match[0]) === 1) return ['gedcom' => $gedcom, 'changed' => false];
        $citation = $prefix;
        if ($page !== '') $citation .= "\n3 PAGE " . $page;
        if ($note !== '') $citation .= "\n3 DATA\n4 TEXT " . str_replace("\n", "\n4 CONT ", $note);
        $updated = $match[0] . "\n" . $citation;
        return ['gedcom' => trim(str_replace($match[0], $updated, $gedcom)), 'changed' => true];
    }

    /** @return array<int,array<string,string>> */
    public static function citations(string $gedcom): array
    {
        $lines = preg_split('/\r?\n/', trim($gedcom)) ?: [];
        $event = '';
        $result = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^1 ([A-Z0-9_]+)(?: |$)/', $line, $eventMatch) === 1) $event = $eventMatch[1];
            if (preg_match('/^(1|2) SOUR @([^@]+)@(?: (.*))?$/', $line, $sourceMatch) !== 1) continue;
            $level = (int) $sourceMatch[1];
            $page = '';
            $note = '';
            for ($next = $index + 1; $next < count($lines) && preg_match('/^' . ($level + 1) . ' /', $lines[$next]) === 1; ++$next) {
                if (preg_match('/^' . ($level + 1) . ' PAGE (.*)$/', $lines[$next], $pageMatch) === 1) $page = $pageMatch[1];
                if (preg_match('/^' . ($level + 1) . ' DATA$/', $lines[$next]) === 1 && isset($lines[$next + 1]) && preg_match('/^' . ($level + 2) . ' TEXT (.*)$/', $lines[$next + 1], $noteMatch) === 1) $note = $noteMatch[1];
            }
            $result[] = ['source-xref' => $sourceMatch[2], 'event' => $level === 2 ? $event : '', 'page' => $page, 'note' => $note];
        }
        return $result;
    }

    private static function replace(string $gedcom, int $level, string $tag, string $value): string
    {
        $pattern = '/\n' . $level . ' ' . preg_quote($tag, '/') . '(?: [^\n]*)?(?:\n[' . ($level + 1) . '-9] [^\n]*)*/';
        if (preg_match_all($pattern, $gedcom) > 1) throw new \DomainException('Multiple ' . $tag . ' fields require manual editing.', 409);
        $result = preg_replace($pattern, '', $gedcom) ?? $gedcom;
        return $value === '' ? $result : $result . "\n" . $level . ' ' . $tag . ' ' . str_replace("\n", "\n" . ($level + 1) . ' CONT ', $value);
    }

    private static function text(mixed $value, string $field): string
    {
        if (!is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new \DomainException('Invalid source field: ' . $field, 400);
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
}
