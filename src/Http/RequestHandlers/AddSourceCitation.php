<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fig\Http\Message\StatusCodeInterface;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
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
        try {
            $tree = SourceContext::tree($this->tree_service, $request);
            if ($tree instanceof ResponseInterface) return $tree;
            $input = $request->getQueryParams();
            $target = SourceContext::record($tree, (string) ($input['target-xref'] ?? ''), true);
            if ($target instanceof ResponseInterface) return $target;
            if (!in_array($target->tag(), ['INDI', 'FAM'], true)) return api_response('target-xref must identify an INDI or FAM record.', 400);
            $write = CheckAccess::checkUserWriteAccess($tree);
            if ($write->getStatusCode() !== 200) return $write;
            if (PendingChangeDetails::rows($tree, $target->xref()) !== []) return api_response(['error' => 'pending_conflict', 'xref' => $target->xref()], 409);

            $citations = $this->citations($tree, $input);
            if ($citations instanceof ResponseInterface) return $citations;

            // Apply every citation to the record before a single updateRecord, so a
            // batch of facts from one document needs only one pending change.
            $gedcom = $target->gedcom();
            $applied = [];
            foreach ($citations as $citation) {
                $result = SourceRecords::addCitation($gedcom, $citation['source-xref'], $citation['event'], $citation['page'], $citation['note']);
                $gedcom = $result['gedcom'];
                if ($result['changed']) $applied[] = ['source-xref' => $citation['source-xref'], 'event' => $citation['event']];
            }

            if (Validator::queryParams($request)->boolean('dry-run', false)) {
                return api_response(['target-xref' => $target->xref(), 'dry-run' => true, 'new-gedcom' => $gedcom, 'citations' => $applied], 200);
            }
            if ($applied === []) {
                return api_response(RecordVersion::receipt($tree, ['target-xref' => $target->xref(), 'changed' => false, 'citations' => []], [$target]), 200);
            }
            $target->updateRecord($gedcom, true);
            return api_response(RecordVersion::receipt($tree, ['target-xref' => $target->xref(), 'pending' => true, 'citations' => $applied], [$target]), 202);
        } catch (DomainException $e) {
            $status = in_array($e->getCode(), [400, 403, 404, 409], true) ? $e->getCode() : 400;
            return api_response($e->getMessage(), $status);
        }
    }

    /**
     * Normalise the single-citation and batched `citations` forms.
     *
     * @param array<string, mixed> $input
     * @return array<int, array{source-xref: string, event: string, page: string, note: string}>|ResponseInterface
     */
    private function citations(Tree $tree, array $input): array|ResponseInterface
    {
        $items = $input['citations'] ?? null;
        if ($items === null) {
            $source = SourceContext::record($tree, (string) ($input['source-xref'] ?? ''), false);
            if ($source instanceof ResponseInterface) return $source;
            if ($source->tag() !== 'SOUR') return api_response('source-xref must identify a SOUR record.', 400);
            $items = [[
                'source-xref' => $source->xref(),
                'event' => (string) ($input['event'] ?? ''),
                'page' => (string) ($input['page'] ?? ''),
                'note' => (string) ($input['note'] ?? ''),
            ]];
        }
        if (!is_array($items) || $items === []) {
            return api_response('citations must be a non-empty array.', 400);
        }

        $citations = [];
        foreach ($items as $item) {
            if (!is_array($item)) return api_response('Each citation must be an object.', 400);
            $source = SourceContext::record($tree, (string) ($item['source-xref'] ?? $input['source-xref'] ?? ''), false);
            if ($source instanceof ResponseInterface) return $source;
            if ($source->tag() !== 'SOUR') return api_response('source-xref must identify a SOUR record.', 400);
            $citations[] = [
                'source-xref' => $source->xref(),
                'event' => strtoupper((string) ($item['event'] ?? '')),
                'page' => (string) ($item['page'] ?? ''),
                'note' => (string) ($item['note'] ?? ''),
            ];
        }

        return $citations;
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_ADD_SOURCE_CITATION,
            'description' => 'Attach one or more SOUR citations to an INDI or FAM record or its events in a single pending change.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tree' => ['type' => 'string'],
                    'source-xref' => [
                        'type' => 'string',
                        'description' => 'Source for the single-citation form; may be omitted when each entry in citations carries its own source-xref.',
                    ],
                    'target-xref' => ['type' => 'string'],
                    'event' => ['type' => 'string'],
                    'page' => ['type' => 'string'],
                    'note' => ['type' => 'string'],
                    'citations' => [
                        'type' => 'array',
                        'description' => 'Batch form: each item is {source-xref, event?, page?, note?}. All are applied in one pending change.',
                        'items' => ['type' => 'object'],
                    ],
                    'dry-run' => [
                        'type' => 'boolean',
                        'description' => 'Preview the resulting GEDCOM without creating a pending change.',
                        'default' => false,
                    ],
                ],
                'required' => ['tree', 'target-xref'],
            ],
            'annotations' => [
                'title' => WebtreesApi::PATH_ADD_SOURCE_CITATION,
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }
}
