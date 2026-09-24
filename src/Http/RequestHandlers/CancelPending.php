<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class CancelPending implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Cancel pending changes for a record before moderator approval.';

    public function __construct(private TreeService $tree_service, private PendingChangesService $pending_changes_service) {}

    #[OA\Delete(path: '/' . WebtreesApi::PATH_CANCEL_PENDING, tags: ['webtrees'], description: self::METHOD_DESCRIPTION)]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $tree_name = Validator::queryParams($request)->string('tree', '');
            $xref = Validator::queryParams($request)->string('xref', '');
            $change_id = Validator::queryParams($request)->string('change-id', '');
            $tree_validation = QueryParamValidator::validateTreeName($this->tree_service, $tree_name);
            if ($tree_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $tree_validation;
            $tree = $this->tree_service->all()[$tree_name];
            $xref_validation = QueryParamValidator::validateXref($tree, $xref);
            if ($xref_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $xref_validation;
            $record = Registry::gedcomRecordFactory()->make($xref, $tree);
            if ($record === null) return api_response('Record not found', StatusCodeInterface::STATUS_NOT_FOUND);
            $record_access = CheckAccess::checkRecordAccess($record, true);
            if ($record_access->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $record_access;
            $write = CheckAccess::checkUserWriteAccess($tree);
            if ($write->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $write;

            $pending = PendingChangeDetails::rows($tree, $xref);
            if ($pending === []) return api_response('No pending changes found', StatusCodeInterface::STATUS_NOT_FOUND);
            if ($change_id === '') {
                $this->pending_changes_service->rejectRecord($record);
            } else {
                $this->pending_changes_service->rejectChange($record, $change_id);
            }

            return api_response(['tree' => $tree_name, 'xref' => $xref, 'cancelled' => count($pending), 'change-id' => $change_id], StatusCodeInterface::STATUS_OK);
        } catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_CANCEL_PENDING,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => ['tree' => McpSchema::TREE, 'xref' => McpSchema::XREF, 'change-id' => ['type' => 'string', 'description' => 'Optional change ID. When supplied, this change and later pending changes for the record are cancelled.']], 'required' => ['tree', 'xref']],
            'outputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'xref' => McpSchema::XREF, 'cancelled' => ['type' => 'integer']], 'required' => ['tree', 'xref', 'cancelled']],
            'annotations' => ['title' => WebtreesApi::PATH_CANCEL_PENDING, 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true, 'deprecated' => false],
        ];
    }
}
