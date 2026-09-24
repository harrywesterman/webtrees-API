<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceContext;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceRecords;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class GetCitations implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = SourceContext::memberRequired($request)) return $denied;
        $tree = SourceContext::tree($this->tree_service, $request);
        if ($tree instanceof ResponseInterface) return $tree;
        $record = SourceContext::record($tree, Validator::queryParams($request)->string('target-xref', ''), false);
        if ($record instanceof ResponseInterface) return $record;
        return api_response(['target-xref' => $record->xref(), 'citations' => SourceRecords::citations($record->gedcom())], 200);
    }
    public static function getMcpToolDescription(): array
    { return ['name' => WebtreesApi::PATH_GET_CITATIONS, 'description' => 'Read SOUR citations from an INDI or FAM record, including event, page and note.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'target-xref' => ['type' => 'string']], 'required' => ['tree', 'target-xref']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_GET_CITATIONS, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]]; }
}
