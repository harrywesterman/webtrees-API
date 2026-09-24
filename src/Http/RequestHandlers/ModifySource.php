<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceContext;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceRecords;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class ModifySource implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = SourceContext::tree($this->tree_service, $request);
        if ($tree instanceof ResponseInterface) return $tree;
        $source = SourceContext::record($tree, Validator::queryParams($request)->string('xref', ''), true);
        if ($source instanceof ResponseInterface) return $source;
        if ($source->tag() !== 'SOUR') return api_response('XREF must identify a SOUR record.', 400);
        $write = CheckAccess::checkUserWriteAccess($tree);
        if ($write->getStatusCode() !== 200) return $write;
        if (PendingChangeDetails::rows($tree, $source->xref()) !== []) return api_response(['error' => 'pending_conflict', 'xref' => $source->xref()], 409);
        $result = SourceRecords::modify($source->gedcom(), $request->getQueryParams());
        if (!$result['changed']) return api_response(['xref' => $source->xref(), 'changed' => false], 200);
        $source->updateRecord($result['gedcom'], true);
        return api_response(['xref' => $source->xref(), 'pending' => true], 202);
    }
    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_MODIFY_SOURCE, 'description' => 'Modify validated fields of a SOUR record without replacing unrelated fields.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'xref' => ['type' => 'string'], 'title' => ['type' => 'string'], 'author' => ['type' => 'string'], 'publication' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['tree', 'xref']], 'annotations' => ['title' => WebtreesApi::PATH_MODIFY_SOURCE, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
