<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class SearchStructured implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = $request->getQueryParams();
        $treeName = Validator::queryParams($request)->string('tree', '');
        if ($treeName !== '') {
            $valid = QueryParamValidator::validateTreeName($this->tree_service, $treeName);
            if ($valid->getStatusCode() !== 200) return $valid;
            $trees = [$this->tree_service->all()[$treeName]];
        } else {
            $trees = $this->tree_service->all()->all();
        }
        $query = trim((string) ($input['query'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $place = trim((string) ($input['place'] ?? ''));
        $occupation = trim((string) ($input['occupation'] ?? ''));
        $fullText = trim((string) ($input['full-text'] ?? $input['full_text'] ?? ''));
        $yearFrom = (int) ($input['year-from'] ?? $input['year_from'] ?? 0);
        $yearTo = (int) ($input['year-to'] ?? $input['year_to'] ?? 0);
        if ($query === '' && $name === '' && $place === '' && $occupation === '' && $fullText === '' && $yearFrom === 0 && $yearTo === 0) return api_response('At least one search criterion is required.', 400);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $rows = [];
        foreach ($trees as $tree) {
            if (!ReadAccess::hasMemberScope($request) && CheckAccess::checkTreePrivacy($tree)->getStatusCode() !== 200) continue;
            $records = DB::table('gedcom')->where('gedcom_id', $tree->id())->orderBy('xref')->get(['xref', 'gedcom_type', 'gedcom']);
            foreach ($records as $row) {
                $gedcom = (string) $row->gedcom;
                if ($query !== '' && stripos($gedcom, $query) === false) continue;
                if ($name !== '' && !$this->lineContains($gedcom, ['NAME'], $name)) continue;
                if ($place !== '' && !$this->lineContains($gedcom, ['PLAC'], $place)) continue;
                if ($occupation !== '' && !$this->lineContains($gedcom, ['OCCU'], $occupation)) continue;
                if ($fullText !== '' && !$this->lineContains($gedcom, ['NOTE', 'TEXT', 'TITL', 'AUTH', 'PUBL'], $fullText)) continue;
                if (!$this->yearMatches($gedcom, $yearFrom, $yearTo)) continue;
                $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree);
                if ($record === null || CheckAccess::checkRecordAccess($record)->getStatusCode() !== 200) continue;
                $rows[] = ['tree' => $tree->name(), 'xref' => $record->xref(), 'record-type' => $record->tag()];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['tree'], $a['xref']] <=> [$b['tree'], $b['xref']]);
        $total = count($rows);
        return api_response(['records' => array_slice($rows, $offset, $limit), 'offset' => $offset, 'limit' => $limit, 'total' => $total, 'has-more' => $offset + $limit < $total], 200);
    }

    private function lineContains(string $gedcom, array $tags, string $needle): bool
    {
        $pattern = '/^(?:[1-9]) (' . implode('|', $tags) . ') (.*)$/mi';
        if (preg_match_all($pattern, $gedcom, $matches) !== false) foreach ($matches[2] as $value) if (stripos($value, $needle) !== false || $this->soundexMatch($value, $needle)) return true;
        return false;
    }

    private function soundexMatch(string $value, string $needle): bool
    {
        $value = preg_replace('/[^\p{L}]+/u', ' ', $value) ?? $value;
        $needle = preg_replace('/[^\p{L}]+/u', ' ', $needle) ?? $needle;
        $first = explode(' ', trim($value))[0] ?? '';
        $term = explode(' ', trim($needle))[0] ?? '';
        return $first !== '' && $term !== '' && soundex($first) === soundex($term);
    }

    private function yearMatches(string $gedcom, int $from, int $to): bool
    {
        if ($from === 0 && $to === 0) return true;
        preg_match_all('/\b(1[0-9]{3}|20[0-9]{2})\b/', $gedcom, $years);
        foreach ($years[1] ?? [] as $year) if (($from === 0 || (int) $year >= $from) && ($to === 0 || (int) $year <= $to)) return true;
        return false;
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_SEARCH_STRUCTURED, 'description' => 'Search records by name, place, year range, occupation, or full text in notes and source fields with stable pagination.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'query' => ['type' => 'string'], 'name' => ['type' => 'string'], 'place' => ['type' => 'string'], 'year-from' => ['type' => 'integer'], 'year-to' => ['type' => 'integer'], 'occupation' => ['type' => 'string'], 'full-text' => ['type' => 'string'], 'offset' => ['type' => 'integer', 'minimum' => 0], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => [], 'additionalProperties' => false], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_SEARCH_STRUCTURED, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
