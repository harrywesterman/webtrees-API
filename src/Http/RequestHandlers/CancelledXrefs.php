<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceContext;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class CancelledXrefs implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $trees) {}

    #[OA\Get(path: '/cancelled-xrefs', tags: ['webtrees'], description: 'List rejected creation xrefs from retained change history. Requires manager rights and member read scope.',
        parameters: [new OA\Parameter(name: 'tree', in: 'query', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 100)), new OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0, default: 0))],
        responses: [new OA\Response(response: 200, description: 'Cancelled XREFs, next-offset and history-complete=false'), new OA\Response(response: 403, description: 'Manager/member access required'), new OA\Response(response: 500, description: 'History read failed')])]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (!ReadAccess::hasMemberScope($request)) return api_response('Cancelled xrefs require member read access.', 403);
            $tree = SourceContext::tree($this->trees, $request);
            if ($tree instanceof ResponseInterface) return $tree;
            if (!Auth::isManager($tree)) return api_response('Cancelled xref history requires tree manager rights.', 403);
            $limit = max(1, min(500, Validator::queryParams($request)->integer('limit', 100)));
            $offset = max(0, Validator::queryParams($request)->integer('offset', 0));
            $rows = DB::table('change')->where('gedcom_id', $tree->id())->where('status', 'rejected')
                ->where('old_gedcom', '')->where('new_gedcom', '<>', '')->orderBy('change_id')->limit($limit + 1)->offset($offset)->get()->all();
            $more = count($rows) > $limit;
            $items = [];
            foreach (array_slice($rows, 0, $limit) as $row) {
                // Renumbering or later reuse must not mark an active xref cancelled.
                if (Registry::gedcomRecordFactory()->make((string) $row->xref, $tree) !== null) continue;
                $items[] = ['xref' => (string) $row->xref, 'change-id' => (string) $row->change_id];
            }
            return api_response(['tree' => $tree->name(), 'cancelled-xrefs' => $items, 'limit' => $limit, 'offset' => $offset,
                'next-offset' => $more ? $offset + $limit : null, 'history-complete' => false,
                'message' => 'Derived from retained rejected creation history. Purged history and other allocation gaps cannot be reconstructed; xrefs are never guaranteed contiguous.'], 200);
        } catch (\Throwable) {
            return api_response('Cancelled xref history could not be read.', 500);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_CANCELLED_XREFS, 'description' => 'List cancelled creation xrefs from retained rejected change history, including creations cancelled in webtrees. Requires member read scope and tree manager rights. Follow next-offset even if a page is empty. Active or reused xrefs are excluded. This is not a complete allocation ledger: purged history and other sequence gaps remain unknown.',
            'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['tree']],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['title' => WebtreesApi::PATH_CANCELLED_XREFS, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
