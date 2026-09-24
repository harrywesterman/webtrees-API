<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$row = (object) [
    'change_id' => 42,
    'xref' => 'I1',
    'change_time' => '2026-09-24 12:00:00',
    'user_name' => 'api-user',
    'real_name' => 'API User',
    'old_gedcom' => '0 @I1@ INDI',
    'new_gedcom' => "0 @I1@ INDI\n1 NAME Test",
];
$serialized = PendingChangeDetails::serialize($row);
check($serialized['change-id'] === '42', 'Change ID is serialized');
check($serialized['xref'] === 'I1' && $serialized['record-type'] === 'INDI', 'Record identity is serialized');
check($serialized['description'] === 'Pending modification of record I1', 'Modification description');
check($serialized['old-gedcom'] === $row->old_gedcom && $serialized['new-gedcom'] === $row->new_gedcom, 'GEDCOM payloads are exposed');

$creation = clone $row;
$creation->old_gedcom = '';
check(PendingChangeDetails::description($creation) === 'Pending creation of record I1', 'Creation description');
$deletion = clone $row;
$deletion->new_gedcom = '';
check(PendingChangeDetails::description($deletion) === 'Pending deletion of record I1', 'Deletion description');

foreach (['ListPendingChanges', 'GetPendingRecord', 'CancelPending'] as $handler) {
    $source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/' . $handler . '.php');
    check(str_contains($source, 'getMcpToolDescription'), $handler . ' MCP description');
    check(str_contains($source, 'PendingChangeDetails'), $handler . ' pending change integration');
}
$modifySource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/ModifyRecord.php');
check(str_contains($modifySource, "'pending_conflict'"), 'Modify conflict code');
check(str_contains($modifySource, "'pending-changes'"), 'Modify conflict details');

echo "Pending-change contract checks passed: {$checks}\n";
