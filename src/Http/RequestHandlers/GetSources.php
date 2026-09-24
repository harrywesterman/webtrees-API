<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceContext;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class GetSources implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = SourceContext::memberRequired($request)) return $denied;
        $tree = SourceContext::tree($this->tree_service, $request);
        if ($tree instanceof ResponseInterface) return $tree;
        $sources = [];
        foreach (DB::table('gedcom')->where('gedcom_id', $tree->id())->where('gedcom_type', 'SOUR')->orderBy('xref')->pluck('xref') as $xref) {
            $record = Registry::sourceFactory()->make((string) $xref, $tree);
            if ($record === null || ReadAccess::validateTree($request, $tree)->getStatusCode() !== 200) continue;
            preg_match('/^1 TITL (.*)$/m', $record->gedcom(), $title);
            $sources[] = ['xref' => $record->xref(), 'title' => $title[1] ?? ''];
        }
        return api_response(['tree' => $request->getQueryParams()['tree'], 'sources' => $sources], 200);
    }
    public static function getMcpToolDescription(): array
    { return ['name' => WebtreesApi::PATH_GET_SOURCES, 'description' => 'List first-class SOUR records in a tree.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string']], 'required' => ['tree']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_GET_SOURCES, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]]; }
}
