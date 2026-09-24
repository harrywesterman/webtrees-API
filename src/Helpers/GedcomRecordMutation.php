<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

final class GedcomRecordMutation
{
    private const array PROTECTED_TAGS = ['FAMS', 'FAMC', 'OBJE', 'CHIL'];

    /**
     * Preserve protected level-one structures missing from the submitted record.
     * The returned list contains the level-one link lines that were re-added.
     *
     * @return array{gedcom: string, preserved: array<string>}
     */
    public static function preserveProtectedLinks(string $before, string $after): array
    {
        $existing = self::protectedLinkKeys($after);
        $preserved = [];
        $blocks = self::protectedBlocks($before);

        foreach ($blocks as $key => $block) {
            if (isset($existing[$key])) {
                continue;
            }

            $after .= "\n" . implode("\n", $block['lines']);
            $existing[$key] = true;
            $preserved[] = $key;
        }

        return [
            'gedcom' => trim($after),
            'preserved' => $preserved,
        ];
    }

    /**
     * Return protected level-one links present before but absent afterwards.
     * The comparison ignores ordering and subordinate lines.
     *
     * @return array<string>
     */
    public static function removedProtectedLinks(string $before, string $after): array
    {
        return array_values(array_diff(
            array_keys(self::protectedLinkKeys($before)),
            array_keys(self::protectedLinkKeys($after)),
        ));
    }

    /**
     * @return array<string, array{lines: array<string>}>
     */
    private static function protectedBlocks(string $gedcom): array
    {
        $lines = preg_split('/\r?\n/', trim($gedcom)) ?: [];
        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^1 (' . implode('|', self::PROTECTED_TAGS) . ') (.+)$/', $line, $match) === 1) {
                if ($current !== null) {
                    $blocks[$current['key']] = $current;
                }

                $current = [
                    'key' => $match[1] . ' ' . $match[2],
                    'lines' => [$line],
                ];
                continue;
            }

            if ($current !== null && preg_match('/^[2-9] /', $line) === 1) {
                $current['lines'][] = $line;
            } elseif ($current !== null && preg_match('/^[01] /', $line) === 1) {
                $blocks[$current['key']] = $current;
                $current = null;
            }
        }

        if ($current !== null) {
            $blocks[$current['key']] = $current;
        }

        return $blocks;
    }

    /**
     * @return array<string, true>
     */
    private static function protectedLinkKeys(string $gedcom): array
    {
        $keys = [];

        foreach (self::protectedBlocks($gedcom) as $key => $_block) {
            $keys[$key] = true;
        }

        return $keys;
    }
}
