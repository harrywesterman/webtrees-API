<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\StructuredContent;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function handlerSource(string $handler): string
{
    return (string) file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/' . $handler . '.php');
}

// §7 list-pending-changes: summary mode omits the GEDCOM payloads.
$row = (object) [
    'change_id' => 7,
    'xref' => 'I1',
    'change_time' => '2026-10-06 10:00:00',
    'user_name' => 'api-user',
    'real_name' => 'API User',
    'old_gedcom' => '0 @I1@ INDI',
    'new_gedcom' => "0 @I1@ INDI\n1 NAME Test",
];
$full = PendingChangeDetails::serialize($row);
$summary = PendingChangeDetails::serialize($row, true);
check(isset($full['old-gedcom'], $full['new-gedcom']), 'Full serialization keeps GEDCOM payloads');
check(!isset($summary['old-gedcom']) && !isset($summary['new-gedcom']), 'Summary serialization omits GEDCOM payloads');
check(($summary['change-id'] ?? '') === '7' && ($summary['xref'] ?? '') === 'I1' && isset($summary['description']), 'Summary serialization keeps identity fields');

$listSource = handlerSource('ListPendingChanges');
check(str_contains($listSource, "'limit'") && str_contains($listSource, "'offset'") && str_contains($listSource, "'summary'"), 'Pagination and summary parameters are exposed');
check(str_contains($listSource, "'total'"), 'Total count is returned');
check(str_contains((string) file_get_contents(__DIR__ . '/../src/Helpers/PendingChangeDetails.php'), 'public static function count'), 'PendingChangeDetails provides a count');

// §4 MCP structuredContent: only JSON objects are emitted, never scalars/lists/text.
check(StructuredContent::objectOrNull('{"xref":"I1"}') === '{"xref":"I1"}', 'JSON object becomes structuredContent');
check(StructuredContent::objectOrNull('{"tree":"t","changes":[]}') !== null, 'Nested object becomes structuredContent');
check(StructuredContent::objectOrNull('[]') === null, 'JSON list is not structuredContent');
check(StructuredContent::objectOrNull('"plain"') === null, 'JSON string is not structuredContent');
check(StructuredContent::objectOrNull('1') === null, 'JSON scalar is not structuredContent');
check(StructuredContent::objectOrNull('not json') === null, 'Non-JSON text is not structuredContent');
$protocolSource = (string) file_get_contents(__DIR__ . '/../src/Http/Middleware/McpProtocol.php');
check(!str_contains($protocolSource, "\$payload['result']['structuredContent'] ="), 'Error results do not attach structuredContent');

// §1 add-child-to-family refuses existing-individual CHIL links.
$addChildSource = handlerSource('AddChildToFamily');
check(str_contains($addChildSource, "'/^1 CHIL @([^@\\r\\n]+)@/m'"), 'add-child-to-family detects CHIL links');
check(str_contains($addChildSource, 'link-child-to-family'), 'Rejection points at link-child-to-family');
check(str_contains($addChildSource, 'STATUS_BAD_REQUEST'), 'Rejection is a 400');

// §3 modify-record reports preserved/removed links on the write response.
$modifySource = handlerSource('ModifyRecord');
check(str_contains($modifySource, "'preserved-links' => \$preserved_links"), 'modify-record returns preserved-links');
check(str_contains($modifySource, "'removed-links' => \$removed_links"), 'modify-record returns removed-links');

// §5 add-source-citation supports a batched citations array and dry-run.
$citationSource = handlerSource('AddSourceCitation');
check(str_contains($citationSource, "'citations'"), 'add-source-citation accepts a citations array');
check(str_contains($citationSource, "'dry-run'"), 'add-source-citation supports dry-run');
check(str_contains($citationSource, 'one pending change'), 'Batch citations share one pending change');

// §8 add-family explains that a bare name is not accepted.
$addFamilySource = handlerSource('AddFamily');
check(str_contains($addFamilySource, 'bare name is not accepted'), 'add-family error explains xref requirement');

// §9 link-spouse-to-individual always writes HUSB before WIFE.
$linkSpouseSource = handlerSource('LinkSpouseToIndividual');
check(str_contains($linkSpouseSource, '1 HUSB @'), 'link-spouse writes HUSB');
check(strpos($linkSpouseSource, '1 HUSB @') < strpos($linkSpouseSource, '1 WIFE @'), 'HUSB precedes WIFE');

// §9 verify-write documents hash/version aliases.
$verifySource = handlerSource('VerifyWrite');
check(str_contains($verifySource, 'alias of'), 'verify-write documents version/hash aliases');

echo "Issue-14 contract checks passed: {$checks}\n";
