<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Helpers\Functions;
use Jefferson49\Webtrees\Log\CustomModuleLog;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\PendingChangeDetails;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
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
    public const string METHOD_DESCRIPTION = 'Cancel pending changes for a record before moderator approval. Cancelling a creation retains a rejected change-history row, readable by managers with cancelled-xrefs. Xrefs are not reused automatically; gaps must not be interpreted as missing records.';

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
            $cancelled = count($pending);
            if ($change_id === '') {
                $this->pending_changes_service->rejectRecord($record);
            } else {
                $this->pending_changes_service->rejectChange($record, $change_id);
            }

            // The rejection is committed at this point. A failure while
            // building the receipt must not turn that into a 500: clients
            // treat an error as "nothing happened" and repeat a destructive
            // call. Report the cancellation, log the receipt problem.
            $payload = ['tree' => $tree_name, 'xref' => $xref, 'cancelled' => $cancelled, 'change-id' => $change_id];
            try {
                return api_response(RecordVersion::receipt($tree, $payload, [$xref]), StatusCodeInterface::STATUS_OK);
            } catch (Throwable $th) {
                CustomModuleLog::addDebugLog(Functions::getFromContainer(WebtreesApi::class), 'cancel-pending receipt failed: ' . $th->getMessage());
                return api_response($payload, StatusCodeInterface::STATUS_OK);
            }
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
