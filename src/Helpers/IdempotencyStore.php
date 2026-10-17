<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Schema\Blueprint;
use Throwable;

/**
 * Idempotency bookkeeping for the write endpoints.
 *
 * Keys are transport metadata, not genealogy: they are stored in a table of
 * their own instead of in the GEDCOM. The former `_WT_API_CREATE` and
 * `_WT_API_IDEMPOTENCY` tags are unknown to webtrees, which shows them as
 * errors on the record page and leaks them into every export.
 *
 * webtrees only migrates its own tables, so the store is created on first use.
 */
final class IdempotencyStore
{
    public const string TABLE = 'webtrees_api_idempotency';

    public const string KIND_RECORDS = 'records';

    public const string KIND_FAMILY = 'family';

    /** Keys are only needed to recognise a replay of a recent write. */
    private const int RETENTION_DAYS = 90;

    /** Create the store on first use. */
    public static function ensureTable(): void
    {
        if (DB::schema()->hasTable(self::TABLE)) {
            return;
        }

        try {
            DB::schema()->create(self::TABLE, static function (Blueprint $table): void {
                $table->increments('idempotency_id');
                $table->integer('gedcom_id');
                $table->string('idempotency_key', 128);
                $table->string('payload_hash', 64);
                $table->string('kind', 16);
                $table->string('local_id', 64);
                $table->string('xref', 20);
                $table->string('created_at', 19);

                $table->unique(['gedcom_id', 'idempotency_key', 'kind', 'local_id'], 'webtrees_api_idempotency_key');
                $table->index(['gedcom_id', 'created_at'], 'webtrees_api_idempotency_age');
            });
        } catch (Throwable $th) {
            // Two requests may race to create the table. Only a store that is
            // still missing afterwards is a real failure.
            if (!DB::schema()->hasTable(self::TABLE)) {
                throw $th;
            }
        }
    }

    /**
     * Rows written for an idempotency key, locked for the writing transaction.
     *
     * @return array{hash:string, xrefs:array<string,string>} empty when unused
     */
    public static function lookup(Tree $tree, string $key, string $kind): array
    {
        $rows = DB::table(self::TABLE)
            ->where('gedcom_id', $tree->id())
            ->where('idempotency_key', $key)
            ->where('kind', $kind)
            ->orderBy('idempotency_id')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $xrefs = [];
        foreach ($rows as $row) {
            $xrefs[(string) $row->local_id] = (string) $row->xref;
        }

        return ['hash' => (string) $rows->first()->payload_hash, 'xrefs' => $xrefs];
    }

    /**
     * Remember which xrefs a key produced.
     *
     * @param array<string,string> $xrefs local id => xref
     */
    public static function record(Tree $tree, string $key, string $hash, string $kind, array $xrefs): void
    {
        if ($xrefs === []) {
            return;
        }

        $created_at = gmdate('Y-m-d H:i:s');
        $rows       = [];
        foreach ($xrefs as $local_id => $xref) {
            $rows[] = [
                'gedcom_id'       => $tree->id(),
                'idempotency_key' => $key,
                'payload_hash'    => $hash,
                'kind'            => $kind,
                'local_id'        => (string) $local_id,
                'xref'            => $xref,
                'created_at'      => $created_at,
            ];
        }

        DB::table(self::TABLE)->insert($rows);
        self::prune($tree);
    }

    /** Forget keys that are too old to be replayed. */
    public static function prune(Tree $tree): void
    {
        DB::table(self::TABLE)
            ->where('gedcom_id', $tree->id())
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400))
            ->delete();
    }

    /**
     * A recorded xref that has no pending or accepted change was cancelled or
     * purged. Replaying its key must not claim the record still exists.
     *
     * @param array<string,string> $xrefs local id => xref
     */
    public static function stillLive(Tree $tree, array $xrefs): bool
    {
        $xrefs = array_values(array_unique($xrefs));
        if ($xrefs === []) {
            return false;
        }

        $live = DB::table('change')
            ->where('gedcom_id', $tree->id())
            ->whereIn('xref', $xrefs)
            ->whereIn('status', ['pending', 'accepted'])
            ->pluck('xref')
            ->all();

        return count(array_unique($live)) === count($xrefs);
    }
}
