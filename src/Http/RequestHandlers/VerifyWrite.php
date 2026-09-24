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
                return api_response(['state' => 'pending_delete', 'xref' => $xref, 'matches' => $expected === ''], 200);
            }
            $version = RecordVersion::fromGedcom((string) $change->new_gedcom);
            return api_response(['state' => 'pending', 'xref' => $xref, ...$version, 'matches' => $expected === '' || hash_equals($version['hash'], $expected)], 200);
        }
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) return api_response(['state' => 'deleted', 'xref' => $xref, 'matches' => $expected === ''], 200);
        $version = RecordVersion::fromRecord($record);
        return api_response(['state' => 'applied', 'xref' => $xref, ...$version, 'matches' => $expected === '' || hash_equals($version['hash'], $expected)], 200);
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_VERIFY_WRITE, 'description' => 'Verify whether a write is pending, applied, or deleted using the record hash.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'xref' => ['type' => 'string'], 'version' => ['type' => 'string'], 'hash' => ['type' => 'string']], 'required' => ['tree', 'xref']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_VERIFY_WRITE, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
