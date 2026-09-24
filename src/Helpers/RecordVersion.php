<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fisharebest\Webtrees\GedcomRecord;

final class RecordVersion
{
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
