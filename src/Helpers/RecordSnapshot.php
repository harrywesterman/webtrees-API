<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;

/** Compare a cached record with current database rows inside a write transaction. */
final class RecordSnapshot
{
    public static function isCurrent(GedcomRecord $record): bool
    {
        return self::read($record)['current'];
    }

    /** @return array{current:bool,pending:array<int,object>} */
    public static function read(GedcomRecord $record): array
    {
        [$table, $prefix] = match ($record->tag()) {
            'INDI' => ['individuals', 'i'],
            'FAM' => ['families', 'f'],
            'SOUR' => ['sources', 's'],
            'OBJE' => ['media', 'm'],
            default => ['other', 'o'],
        };
        // Locking reads bypass a MySQL repeatable-read snapshot. Native webtrees
        // writers lock these same record rows; a tree lock alone cannot protect us.
        $approved = DB::table($table)->where($prefix . '_file', $record->tree()->id())
            ->where($prefix . '_id', $record->xref())->lockForUpdate()->value($prefix . '_gedcom');
        $rows = PendingChangeDetails::lockedRows($record->tree(), $record->xref());
        if ($rows !== []) {
            $latest = $rows[array_key_last($rows)];
            return ['current' => $latest->new_gedcom !== '' && $latest->new_gedcom === $record->gedcom(), 'pending' => $rows];
        }
        return ['current' => !$record->isPendingAddition() && $approved !== null && $approved === $record->gedcom(), 'pending' => []];
    }
}
