<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$childSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/UnlinkChild.php');
$spouseSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/UnlinkSpouse.php');
$helperSource = file_get_contents(__DIR__ . '/../src/Helpers/RelationshipLinks.php');
$permissionSource = file_get_contents(__DIR__ . '/../src/Http/Middleware/McpToolPermission.php');
$dispatcherSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/McpTool.php');
$apiSource = file_get_contents(__DIR__ . '/../src/WebtreesApi.php');
$childToolName = "PATH_UNLINK_CHILD         = 'unlink-child'";
$spouseToolName = "PATH_UNLINK_SPOUSE       = 'unlink-spouse'";
check(str_contains($apiSource, $childToolName) && str_contains($apiSource, $spouseToolName), 'Unlink route constants');
check(str_contains($permissionSource, 'PATH_UNLINK_CHILD') && str_contains($permissionSource, 'PATH_UNLINK_SPOUSE'), 'Unlink write permissions');
check(str_contains($dispatcherSource, 'PATH_UNLINK_CHILD') && str_contains($dispatcherSource, 'PATH_UNLINK_SPOUSE'), 'Unlink MCP dispatch');
foreach ([$childSource, $spouseSource] as $source) {
    check(str_contains($source, "'individual-xref'") && str_contains($source, "'family-xref'"), 'MCP relationship inputs');
    check(str_contains($source, "'readOnlyHint' => false") && str_contains($source, "'destructiveHint' => true"), 'MCP write annotations');
    check(str_contains($source, "'idempotentHint' => true") && str_contains($source, "'xref' => McpSchema::XREF"), 'MCP output annotations');
}
check(str_contains($childSource, "['FAMC']") && str_contains($childSource, "['CHIL']"), 'Child unlink updates both sides');
check(str_contains($spouseSource, "['FAMS']") && str_contains($spouseSource, "['HUSB', 'WIFE']"), 'Spouse unlink updates both sides');
check(str_contains($helperSource, 'deleteFact'), 'Relationship unlink deletes facts');

echo "Relationship unlink contract checks passed: {$checks}\n";
