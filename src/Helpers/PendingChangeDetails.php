<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\DB;

final class PendingChangeDetails
{
    /**
     * @return array<int, object>
     */
    public static function rows(Tree $tree, string $xref = '', int $limit = 0, int $offset = 0): array
    {
        $query = DB::table('change')
            ->leftJoin('user', 'user.user_id', '=', 'change.user_id')
            ->where('change.gedcom_id', '=', $tree->id())
            ->where('change.status', '=', 'pending')
            ->select(['change.*', 'user.user_name', 'user.real_name'])
            ->orderBy('change.change_id');

        if ($xref !== '') {
            $query->where('change.xref', '=', $xref);
        }

        if ($limit > 0) {
            $query->limit($limit)->offset(max(0, $offset));
        }

        return $query->get()->all();
    }

    /**
     * Count pending changes for a tree, optionally limited to one record.
     */
    public static function count(Tree $tree, string $xref = ''): int
    {
        $query = DB::table('change')
            ->where('change.gedcom_id', '=', $tree->id())
            ->where('change.status', '=', 'pending');

        if ($xref !== '') {
            $query->where('change.xref', '=', $xref);
        }

        return $query->count();
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(object $row, bool $summary = false): array
    {
        $gedcom = (string) ($row->new_gedcom ?: $row->old_gedcom);
        preg_match('/^0 (?:@[^@]+@ )?([^ \r\n]+)/', $gedcom, $match);

        $data = [
            'change-id' => (string) $row->change_id,
            'xref' => (string) $row->xref,
            'record-type' => $match[1] ?? 'UNKNOWN',
            'change-time' => (string) $row->change_time,
            'user' => (string) ($row->user_name ?? ''),
            'real-name' => (string) ($row->real_name ?? ''),
            'description' => self::description($row),
        ];

        if (!$summary) {
            $data['old-gedcom'] = (string) $row->old_gedcom;
            $data['new-gedcom'] = (string) $row->new_gedcom;
        }

        return $data;
    }

    public static function description(object $row): string
    {
        if ((string) $row->new_gedcom === '') {
            return 'Pending deletion of record ' . $row->xref;
        }

        if ((string) $row->old_gedcom === '') {
            return 'Pending creation of record ' . $row->xref;
        }

        return 'Pending modification of record ' . $row->xref;
    }
}
