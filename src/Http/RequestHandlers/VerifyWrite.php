<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class VerifyWrite implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!ReadAccess::hasMemberScope($request)) return api_response('Verify-write requires member read access.', 403);
        $input = $request->getQueryParams();
        $treeName = Validator::queryParams($request)->string('tree', '');
        $xref = Validator::queryParams($request)->string('xref', '');
        $treeValidation = QueryParamValidator::validateTreeName($this->tree_service, $treeName);
        if ($treeValidation->getStatusCode() !== 200) return $treeValidation;
        $tree = $this->tree_service->all()[$treeName];
        if (!preg_match('/^' . Gedcom::REGEX_XREF . '$/', $xref)) return api_response('Invalid xref parameter.', 400);
        $expected = (string) ($input['version'] ?? $input['hash'] ?? '');
        $pending = PendingChangeDetails::rows($tree, $xref);
        if ($pending !== []) {
            $change = $pending[array_key_last($pending)];
            if ((string) $change->new_gedcom === '') {
                return api_response(['state' => 'pending_delete', 'xref' => $xref, ...RecordVersion::fromGedcom(''), 'matches' => $expected === '' ? null : hash_equals(RecordVersion::fromGedcom('')['hash'], $expected)], 200);
            }
            $version = RecordVersion::fromGedcom((string) $change->new_gedcom);
            return api_response(['state' => 'pending', 'xref' => $xref, ...$version, 'matches' => $expected === '' ? null : hash_equals($version['hash'], $expected)], 200);
        }
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) return api_response(['state' => 'deleted', 'xref' => $xref, ...RecordVersion::fromGedcom(''), 'matches' => $expected === '' ? null : hash_equals(RecordVersion::fromGedcom('')['hash'], $expected)], 200);
        $version = RecordVersion::fromRecord($record);
        return api_response(['state' => 'applied', 'xref' => $xref, ...$version, 'matches' => $expected === '' ? null : hash_equals($version['hash'], $expected)], 200);
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_VERIFY_WRITE, 'description' => 'Verify whether a write is pending, applied, pending_delete, or deleted. Write responses return a SHA-256 `hash` (also exposed as `version`); pass that value as `version` or `hash` and the response reports the current `state` and whether it `matches` the expected value. Omit the hash to only read state: matches is null and does not confirm a write. Example: modify-record returns xref=I1, hash=abc...; call verify-write with tree, xref=I1, hash=abc... and require matches=true. Deletions use SHA-256 of empty GEDCOM.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'xref' => ['type' => 'string'], 'version' => ['type' => 'string', 'description' => 'Expected record hash returned by a write response (alias of `hash`).'], 'hash' => ['type' => 'string', 'description' => 'Expected record hash returned by a write response (alias of `version`).']], 'required' => ['tree', 'xref']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_VERIFY_WRITE, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
