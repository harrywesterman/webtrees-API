<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use DomainException;

/** Validate an entire batch before allocating an XREF or writing a record. */
final class RecordBatch
{
    public static function prepare(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > 100) {
            throw new DomainException('records must contain between 1 and 100 objects.', 400);
        }
        $records = [];
        foreach ($input as $item) {
            if (!is_array($item)) throw new DomainException('Each record must be an object.', 400);
            $id = $item['id'] ?? '';
            $type = $item['record-type'] ?? '';
            $gedcom = $item['gedcom'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_:-]{1,64}$/D', $id) || isset($records[$id])) {
                throw new DomainException('Each record needs a unique safe local id.', 400);
            }
            if (!in_array($type, ['INDI', 'FAM', 'SOUR', 'NOTE', 'REPO', 'OBJE', '_LOC', 'SUBM'], true) || !is_string($gedcom)) {
                throw new DomainException('Invalid record-type or gedcom.', 400);
            }
            $gedcom = trim(str_replace(["\r\n", "\r"], "\n", $gedcom));
            $previous = 0;
            foreach ($gedcom === '' ? [] : explode("\n", $gedcom) as $line) {
                if (!preg_match('/^([1-9]) ([A-Z_][A-Z0-9_]*)(?: .*)?$/D', $line, $match) || (int) $match[1] > $previous + 1) {
                    throw new DomainException('Invalid GEDCOM fragment or level-zero line in ' . $id, 400);
                }
                if (str_starts_with($line, '1 _WT_API_')) throw new DomainException('API bookkeeping tags are reserved.', 400);
                $previous = (int) $match[1];
            }
            $records[$id] = ['record-type' => $type, 'gedcom' => $gedcom];
        }
        return $records;
    }

    public static function references(string $gedcom): array
    {
        preg_match_all('/^[1-9] [A-Z_][A-Z0-9_]* @([^@\n]+)@$/m', $gedcom, $matches);
        return array_values(array_unique($matches[1]));
    }

    public static function resolve(string $gedcom, array $xrefs): string
    {
        return preg_replace_callback('/^([1-9] [A-Z_][A-Z0-9_]* )@([^@\n]+)@$/m',
            static fn (array $match): string => $match[1] . '@' . ($xrefs[$match[2]] ?? $match[2]) . '@', $gedcom);
    }
}
