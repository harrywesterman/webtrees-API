<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Tree;

final class RecordVersion
{
    /** Prefer the objects just written: they retain the exact submitted GEDCOM, including CHAN. Xref lookups must run under the writer transaction lock. */
    public static function receipt(Tree $tree, array $payload, array $xrefs): array
    {
        $records = [];
        foreach ($xrefs as $value) {
            if ($value instanceof GedcomRecord) {
                $xref = $value->xref();
                $gedcom = $value->gedcom();
                $state = $value->isPendingDeletion() ? 'pending_delete' : ($value->isPendingAddition() ? 'pending' : ($gedcom === '' ? 'deleted' : 'applied'));
                $records[$xref] = ['xref' => $xref, 'state' => $state, ...self::fromGedcom($gedcom)];
                continue;
            }
            $xref = (string) $value;
            $pending = DB::table('change')->where('gedcom_id', $tree->id())->where('xref', $xref)->where('status', 'pending')->orderByDesc('change_id')->lockForUpdate()->first();
            if ($pending !== null) {
                $gedcom = (string) $pending->new_gedcom;
                $state = $gedcom === '' ? 'pending_delete' : 'pending';
            } else {
                // A replay may discover this xref after waiting for another
                // writer. Factory caches and consistent reads can still predate
                // that commit, so fetch the approved GEDCOM with a current read.
                $gedcom = '';
                foreach (['individuals' => 'i', 'families' => 'f', 'sources' => 's', 'media' => 'm', 'other' => 'o'] as $table => $prefix) {
                    $approved = DB::table($table)->where($prefix . '_file', $tree->id())->where($prefix . '_id', $xref)->lockForUpdate()->value($prefix . '_gedcom');
                    if ($approved !== null) {
                        $gedcom = (string) $approved;
                        break;
                    }
                }
                $state = $gedcom === '' ? 'deleted' : 'applied';
            }
            $records[$xref] = ['xref' => $xref, 'state' => $state, ...self::fromGedcom($gedcom)];
        }
        $records = array_values($records);
        if ($records !== []) {
            $primary = $payload['xref'] ?? $payload['target-xref'] ?? $payload['family-xref'] ?? $records[0]['xref'];
            $receipt = array_values(array_filter($records, static fn (array $item): bool => $item['xref'] === $primary))[0] ?? $records[0];
            $payload['hash'] = $receipt['hash'];
            $payload['version'] = $receipt['version'];
        }
        $payload['records'] = $records;
        return $payload;
    }

    /** @return array{version:string,hash:string} */
    public static function fromGedcom(string $gedcom): array
    {
        $hash = hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $gedcom)));
        return ['version' => $hash, 'hash' => $hash];
    }

    /** @return array{version:string,hash:string} */
    public static function fromRecord(GedcomRecord $record): array
    {
        return self::fromGedcom($record->gedcom());
    }
}
