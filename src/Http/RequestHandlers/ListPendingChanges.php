<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Parameter\Tree as TreeParameter;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class ListPendingChanges implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'List pending approval-queue changes for a tree, optionally limited to one record.';

    public function __construct(private TreeService $tree_service) {}

    #[OA\Get(path: '/' . WebtreesApi::PATH_LIST_PENDING_CHANGES, tags: ['webtrees'], description: self::METHOD_DESCRIPTION)]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $tree_name = Validator::queryParams($request)->string('tree', '');
            $xref = Validator::queryParams($request)->string('xref', '');
            $limit = Validator::queryParams($request)->integer('limit', 100);
            $offset = Validator::queryParams($request)->integer('offset', 0);
            $summary = Validator::queryParams($request)->boolean('summary', false);
            $tree_validation = QueryParamValidator::validateTreeName($this->tree_service, $tree_name);
            if ($tree_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $tree_validation;
            $tree = $this->tree_service->all()[$tree_name];

            if (!ReadAccess::hasMemberScope($request)) {
                return api_response('Pending changes require member read access.', StatusCodeInterface::STATUS_FORBIDDEN);
            }

            $limit = max(0, min($limit, 500));
            $offset = max(0, $offset);
            $total = PendingChangeDetails::count($tree, $xref);
            $changes = array_map(
                static fn (object $row): array => PendingChangeDetails::serialize($row, $summary),
                PendingChangeDetails::rows($tree, $xref, $limit, $offset),
            );

            return api_response([
                'tree' => $tree_name,
                'changes' => $changes,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'summary' => $summary,
            ], StatusCodeInterface::STATUS_OK);
        } catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_LIST_PENDING_CHANGES,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => McpSchema::TREE,
                'xref' => McpSchema::withDescription(McpSchema::XREF, 'Optional record XREF filter.', McpSchema::APPEND),
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of changes to return (default 100, max 500; 0 returns all).', 'default' => 100],
                'offset' => ['type' => 'integer', 'description' => 'Number of changes to skip for pagination.', 'default' => 0],
                'summary' => ['type' => 'boolean', 'description' => 'Omit old-gedcom/new-gedcom payloads and return only change-id, xref, record-type, change-time, user and description.', 'default' => false],
            ], 'required' => ['tree']],
            'outputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'changes' => ['type' => 'array'], 'total' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer'], 'summary' => ['type' => 'boolean']], 'required' => ['tree', 'changes']],
            'annotations' => ['title' => WebtreesApi::PATH_LIST_PENDING_CHANGES, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true, 'deprecated' => false],
        ];
    }
}
