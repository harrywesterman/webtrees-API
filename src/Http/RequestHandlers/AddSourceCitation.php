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

final class AddSourceCitation implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = SourceContext::tree($this->tree_service, $request);
        if ($tree instanceof ResponseInterface) return $tree;
        $input = $request->getQueryParams();
        $source = SourceContext::record($tree, (string) ($input['source-xref'] ?? ''), false);
        if ($source instanceof ResponseInterface) return $source;
        if ($source->tag() !== 'SOUR') return api_response('source-xref must identify a SOUR record.', 400);
        $target = SourceContext::record($tree, (string) ($input['target-xref'] ?? ''), true);
        if ($target instanceof ResponseInterface) return $target;
        if (!in_array($target->tag(), ['INDI', 'FAM'], true)) return api_response('target-xref must identify an INDI or FAM record.', 400);
        $write = CheckAccess::checkUserWriteAccess($tree);
        if ($write->getStatusCode() !== 200) return $write;
        if (PendingChangeDetails::rows($tree, $target->xref()) !== []) return api_response(['error' => 'pending_conflict', 'xref' => $target->xref()], 409);
        $result = SourceRecords::addCitation($target->gedcom(), $source->xref(), strtoupper((string) ($input['event'] ?? '')), (string) ($input['page'] ?? ''), (string) ($input['note'] ?? ''));
        if (!$result['changed']) return api_response(['target-xref' => $target->xref(), 'source-xref' => $source->xref(), 'changed' => false], 200);
        $target->updateRecord($result['gedcom'], true);
        return api_response(['target-xref' => $target->xref(), 'source-xref' => $source->xref(), 'pending' => true], 202);
    }
    public static function getMcpToolDescription(): array
    { return ['name' => WebtreesApi::PATH_ADD_SOURCE_CITATION, 'description' => 'Attach a SOUR citation to an INDI or FAM record or one of its events.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'source-xref' => ['type' => 'string'], 'target-xref' => ['type' => 'string'], 'event' => ['type' => 'string'], 'page' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['tree', 'source-xref', 'target-xref']], 'annotations' => ['title' => WebtreesApi::PATH_ADD_SOURCE_CITATION, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]]; }
}
