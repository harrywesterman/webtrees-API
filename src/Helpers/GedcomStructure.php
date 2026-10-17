<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\Elements\UnknownElement;
use Fisharebest\Webtrees\Registry;
use Throwable;

/**
 * Pre-flight check of a GEDCOM fragment against the structures webtrees knows.
 *
 * webtrees renders a tag it does not recognise as a red "Unrecognized GEDCOM
 * code" line on the record page, and only after approval. The element factory
 * is exactly the registry behind that decision (the RegisterGedcomTags
 * middleware fills it), so validating with it keeps the module's answer and
 * webtrees' rendering in sync instead of duplicating an allowlist.
 */
final class GedcomStructure
{
    /**
     * Unknown structures, keyed by their colon-delimited path.
     *
     * @param string $record_type    INDI, FAM, SOUR, ...
     * @param string $gedcom         the record (with or without its level 0 line)
     * @param string $tolerated      existing GEDCOM whose unknown paths are left alone
     *
     * @return array<string,array<int,int>> path => 1-based line numbers
     */
    public static function problems(string $record_type, string $gedcom, string $tolerated = ''): array
    {
        $record_type = strtoupper(trim($record_type));
        $factory     = self::elementFactory();

        if ($factory === null || $record_type === '') {
            return [];
        }

        // No tags registered for this record type means the registry is not
        // available in this context (or the type is a custom one webtrees
        // handles elsewhere). Never reject a whole record on that basis.
        if ($factory->make($record_type) instanceof UnknownElement) {
            return [];
        }

        $tolerated_paths = array_keys(self::paths($record_type, $tolerated));

        $problems = [];
        foreach (self::paths($record_type, $gedcom) as $path => $lines) {
            if (in_array($path, $tolerated_paths, true)) {
                continue;
            }
            if (!self::isKnown($factory, $path)) {
                $problems[$path] = $lines;
            }
        }

        return $problems;
    }

    /**
     * webtrees accepts a tag either as an element of its own, or as a sub-field
     * the parent element declares (that is how `_LOC`, `_GOV` and friends are
     * registered under PLAC). Unknown parents are reported on their own level,
     * so their children are not reported twice.
     */
    private static function isKnown(object $factory, string $path): bool
    {
        if (!($factory->make($path) instanceof UnknownElement)) {
            return true;
        }

        $separator = strrpos($path, ':');
        if ($separator === false) {
            return false;
        }

        $parent  = substr($path, 0, $separator);
        $tag     = substr($path, $separator + 1);
        $element = $factory->make($parent);

        if ($element instanceof UnknownElement) {
            return true;
        }

        $subtags = self::subtags($element);

        // Some elements build their sub-tag list from site preferences. When
        // that cannot be read, accept the tag rather than reject valid GEDCOM.
        if ($subtags === null) {
            return true;
        }

        return array_key_exists($tag, $subtags);
    }

    /**
     * A message a client can act on: which paths are wrong, and what webtrees
     * does accept at that position.
     *
     * @param array<string,array<int,int>> $problems
     */
    public static function message(array $problems): string
    {
        $factory  = self::elementFactory();
        $rejected = [];
        $allowed  = [];

        foreach (array_slice($problems, 0, 3, true) as $path => $lines) {
            $rejected[] = $path . ' (line ' . implode(',', $lines) . ')';

            $parent = substr($path, 0, (int) strrpos($path, ':'));
            if ($parent !== '' && $allowed === [] && $factory !== null) {
                $subtags = self::subtags($factory->make($parent));
                if ($subtags !== null && $subtags !== []) {
                    $subtags = array_keys($subtags);
                    sort($subtags);
                    $allowed = [$parent, $subtags];
                }
            }
        }

        $message = 'Webtrees would show this as a GEDCOM error: ' . implode(', ', $rejected) . '.';

        if ($allowed !== []) {
            $message .= ' Allowed under ' . $allowed[0] . ': ' . implode(' ', $allowed[1]) . '.';
        }

        if (str_starts_with((string) array_key_first($problems), 'SOUR:')) {
            $message .= ' A source record keeps DATE/PLAC under DATA > EVEN and the transcript in TEXT.';
        }

        return $message;
    }

    /**
     * All paths in a fragment, with the lines they occur on.
     *
     * @return array<string,array<int,int>>
     */
    private static function paths(string $record_type, string $gedcom): array
    {
        $paths = [];
        $stack = [$record_type];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $gedcom)) as $index => $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            // The level 0 line repeats the record type; start a fresh path.
            if (preg_match('/^0 (?:@[^@]+@ )?[A-Za-z0-9_]+/', $line) === 1) {
                $stack = [$record_type];
                continue;
            }

            // Malformed levels are rejected by the regular GEDCOM validation.
            if (preg_match('/^([1-9][0-9]*) ([A-Za-z0-9_]+)(?: |$)/', $line, $match) !== 1) {
                continue;
            }

            $level          = (int) $match[1];
            $tag            = strtoupper($match[2]);

            // Continuation lines belong to the value above them; webtrees
            // merges them before it resolves any element.
            if ($tag === 'CONT' || $tag === 'CONC') {
                continue;
            }

            $stack          = array_slice($stack, 0, $level);
            $stack[$level]  = $tag;
            $paths[implode(':', $stack)][] = $index + 1;
        }

        return $paths;
    }

    private static function elementFactory(): object|null
    {
        try {
            return Registry::elementFactory();
        } catch (Throwable) {
            // Outside a webtrees request (tests, CLI) the factory may not exist.
            return null;
        }
    }

    /**
     * Sub-tags of an element, or null when webtrees cannot answer (some
     * elements read their sub-tags from site preferences).
     *
     * @return array<string,string>|null
     */
    private static function subtags(object $element): ?array
    {
        if (!method_exists($element, 'subtags')) {
            return [];
        }

        try {
            return $element->subtags();
        } catch (Throwable) {
            return null;
        }
    }
}
