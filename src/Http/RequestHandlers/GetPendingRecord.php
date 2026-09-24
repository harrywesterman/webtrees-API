<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class GetPendingRecord implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Get the pending GEDCOM changes for one record.';

    public function __construct(private TreeService $tree_service) {}

    #[OA\Get(path: '/' . WebtreesApi::PATH_GET_PENDING_RECORD, tags: ['webtrees'], description: self::METHOD_DESCRIPTION)]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $tree_name = Validator::queryParams($request)->string('tree', '');
            $xref = Validator::queryParams($request)->string('xref', '');
            $tree_validation = QueryParamValidator::validateTreeName($this->tree_service, $tree_name);
            if ($tree_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $tree_validation;
            $tree = $this->tree_service->all()[$tree_name];

            if (!ReadAccess::hasMemberScope($request)) {
                return api_response('Pending records require member read access.', StatusCodeInterface::STATUS_FORBIDDEN);
            }

            $xref_validation = QueryParamValidator::validateXref($tree, $xref);
            if ($xref_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $xref_validation;
            $record = Registry::gedcomRecordFactory()->make($xref, $tree);
            if ($record === null) return api_response('Record not found', StatusCodeInterface::STATUS_NOT_FOUND);
            $record_access = CheckAccess::checkRecordAccess($record, false);
            if ($record_access->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $record_access;

            $changes = array_map(
                static fn (object $row): array => PendingChangeDetails::serialize($row),
                PendingChangeDetails::rows($tree, $xref),
            );
            if ($changes === []) return api_response('No pending changes found', StatusCodeInterface::STATUS_NOT_FOUND);

            return api_response(['tree' => $tree_name, 'xref' => $xref, 'changes' => $changes], StatusCodeInterface::STATUS_OK);
        } catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_GET_PENDING_RECORD,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => ['tree' => McpSchema::TREE, 'xref' => McpSchema::XREF], 'required' => ['tree', 'xref']],
            'outputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'xref' => McpSchema::XREF, 'changes' => ['type' => 'array']], 'required' => ['tree', 'xref', 'changes']],
            'annotations' => ['title' => WebtreesApi::PATH_GET_PENDING_RECORD, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true, 'deprecated' => false],
        ];
    }
}
