<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordBatch;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceContext;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class CreateRecords implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $trees) {}

    #[OA\Post(path: '/create-records', tags: ['webtrees'], description: 'Create linked GEDCOM records atomically. Local IDs in pointers resolve to allocated XREFs; include reciprocal family links explicitly.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['tree', 'idempotency-key', 'records'], properties: [
            new OA\Property(property: 'tree', type: 'string'), new OA\Property(property: 'idempotency-key', type: 'string'),
            new OA\Property(property: 'records', type: 'array', minItems: 1, maxItems: 100, items: new OA\Items(type: 'object', required: ['id', 'record-type', 'gedcom'], properties: [
                new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'record-type', type: 'string'), new OA\Property(property: 'gedcom', type: 'string'),
            ])),
        ])), responses: [new OA\Response(response: 202, description: 'All records submitted; returns xrefs, hashes and per-record receipts.'), new OA\Response(response: 200, description: 'Idempotent replay'), new OA\Response(response: 400, description: 'Invalid batch'), new OA\Response(response: 403, description: 'Access denied'), new OA\Response(response: 409, description: 'Idempotency conflict'), new OA\Response(response: 500, description: 'Transaction failed and rolled back')])]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = $request->getQueryParams();
            $body = $request->getParsedBody();
            if (is_array($body)) {
                foreach ($body as $name => $value) {
                    if (array_key_exists($name, $input) && $input[$name] !== $value) throw new DomainException('Conflicting query and JSON body values.', 400);
                }
                $input = array_replace($input, $body);
                $request = $request->withQueryParams($input);
            }
            $tree = SourceContext::tree($this->trees, $request);
            if ($tree instanceof ResponseInterface) return $tree;
            $access = CheckAccess::checkUserWriteAccess($tree);
            if ($access->getStatusCode() !== 200) return $access;
            $key = $input['idempotency-key'] ?? '';
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9.:-]{8,128}$/D', $key)) throw new DomainException('idempotency-key must be 8-128 safe characters.', 400);
            $prepared = RecordBatch::prepare($input['records'] ?? null);
            $fingerprint = hash('sha256', json_encode($prepared, JSON_THROW_ON_ERROR));
            $result = DB::connection()->transaction(function () use ($tree, $key, $prepared, $fingerprint): array|ResponseInterface {
                DB::table('gedcom')->where('gedcom_id', $tree->id())->lockForUpdate()->first();
                // The native change log survives approval; rejected writes are
                // excluded. A key binds to the complete payload, never just a name.
                $history = DB::table('change')->where('gedcom_id', $tree->id())->whereIn('status', ['pending', 'accepted'])
                    ->where('new_gedcom', 'like', '%1 _WT_API_CREATE ' . $key . ' %')->orderBy('change_id')->lockForUpdate()->get();
                $prior = [];
                foreach ($history as $row) {
                    if (preg_match('/^1 _WT_API_CREATE ' . preg_quote($key, '/') . ' ([a-f0-9]{64}) ([A-Za-z0-9_:-]+)$/m', $row->new_gedcom, $match)) {
                        if (!hash_equals($fingerprint, $match[1])) throw new DomainException('Idempotency key was already used for a different records payload.', 409);
                        $prior[$match[2]] = (string) $row->xref;
                    }
                }
                if ($prior !== []) {
                    if (count($prior) !== count($prepared)) throw new DomainException('Part of the prior batch was cancelled; inspect it before retrying with a new key.', 409);
                    return RecordVersion::receipt($tree, ['xref' => reset($prior), 'xrefs' => (object) $prior, 'idempotent' => true], array_values($prior));
                }
                foreach ($prepared as $item) {
                    foreach (RecordBatch::references($item['gedcom']) as $reference) {
                        if (isset($prepared[$reference])) continue;
                        $record = SourceContext::record($tree, $reference, false);
                        if ($record instanceof ResponseInterface) return $record;
                        if ($record->isPendingDeletion()) throw new DomainException('Referenced record has a pending deletion: ' . $reference, 409);
                    }
                }
                $xrefs = [];
                foreach ($prepared as $id => $item) {
                    $xrefs[$id] = Registry::xrefFactory()->make($item['record-type']);
                }
                // Allocate first, then submit one complete native creation change
                // per xref. Never expose an independently approvable skeleton.
                $chan = "\n1 CHAN\n2 DATE " . strtoupper(date('d M Y')) . "\n3 TIME " . date('H:i:s')
                    . "\n2 _WT_USER " . Auth::user()->userName();
                foreach ($prepared as $id => $item) {
                    $gedcom = '0 @' . $xrefs[$id] . '@ ' . $item['record-type'] . "\n" . RecordBatch::resolve($item['gedcom'], $xrefs)
                        . "\n1 _WT_API_CREATE " . $key . ' ' . $fingerprint . ' ' . $id . $chan;
                    DB::table('change')->insert([
                        'gedcom_id' => $tree->id(), 'xref' => $xrefs[$id], 'old_gedcom' => '',
                        'new_gedcom' => $gedcom, 'status' => 'pending', 'user_id' => Auth::id(),
                    ]);
                }
                return RecordVersion::receipt($tree, ['xref' => reset($xrefs), 'xrefs' => (object) $xrefs, 'idempotent' => false, 'pending' => true], array_values($xrefs));
            });
            if ($result instanceof ResponseInterface) return $result;
            return api_response($result, $result['idempotent'] ? 200 : 202);
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable) {
            return api_response('Record batch failed. The transaction was rolled back.', 500);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_CREATE_RECORDS,
            'description' => 'Create 1-100 GEDCOM records in one database transaction. Each record has a local id, record-type and GEDCOM fragment without a level-zero line. Pointers such as @person@ reference another local id and are replaced by allocated xrefs; other pointers must identify accessible existing records. Include reciprocal FAMS/FAMC and HUSB/WIFE/CHIL links explicitly. Example: records=[{id:person,record-type:INDI,gedcom:"1 NAME Test\\n1 FAMS @family@"},{id:family,record-type:FAM,gedcom:"1 HUSB @person@"}]. An idempotency-key binds to the full payload. Returns every xref plus per-record hash/version. Moderator approval still occurs per record; database atomicity does not imply group approval.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => ['type' => 'string'], 'idempotency-key' => ['type' => 'string'],
                'records' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string'], 'record-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR', 'NOTE', 'REPO', 'OBJE', '_LOC', 'SUBM']],
                    'gedcom' => ['type' => 'string', 'description' => 'Level-one facts, including NOTE and nested source citations.'],
                ], 'required' => ['id', 'record-type', 'gedcom'], 'additionalProperties' => false]],
            ], 'required' => ['tree', 'idempotency-key', 'records'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['title' => WebtreesApi::PATH_CREATE_RECORDS, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
