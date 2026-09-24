<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Helpers;

use Fisharebest\Webtrees\GedcomRecord;

final class RelationshipLinks
{
    /**
     * Delete every fact with one of the supplied tags that points at $target.
     */
    public static function removeFactsTo(GedcomRecord $record, array $tags, GedcomRecord $target): int
    {
        $fact_ids = [];

        foreach (Functions::getRecordFacts($record, $tags, false, null, true) as $fact) {
            if ($target === $fact->target()) {
                $fact_ids[] = $fact->id();
            }
        }

        foreach ($fact_ids as $fact_id) {
            $record->deleteFact($fact_id, true);
        }

        return count($fact_ids);
    }
}
