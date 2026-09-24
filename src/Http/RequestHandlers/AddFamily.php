<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class AddFamily implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = $request->getQueryParams();
            $treeName = Validator::queryParams($request)->string('tree', '');
            $valid = QueryParamValidator::validateTreeName($this->tree_service, $treeName);
            if ($valid->getStatusCode() !== 200) return $valid;
            $tree = $this->tree_service->all()[$treeName];
            $write = CheckAccess::checkUserWriteAccess($tree);
            if ($write->getStatusCode() !== 200) return $write;
            $key = trim((string) ($input['idempotency-key'] ?? $input['idempotency_key'] ?? ''));
            if ($key === '' || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) return api_response('idempotency-key is required and must be 8-128 safe characters.', 400);
            $marker = '1 _WT_API_IDEMPOTENCY ' . $key;
            $existing = DB::table('families')->where('f_file', $tree->id())->where('f_gedcom', 'like', '%' . $marker . '%')->get(['f_id']);
            if ($existing->isNotEmpty()) {
                $family = $existing->first();
                return api_response(['idempotent' => true, 'family-xref' => $family->f_id, 'record-xrefs' => [$family->f_id]], 200);
            }
            if (!array_key_exists('husband', $input) && !array_key_exists('wife', $input) && empty($input['children'])) return api_response('At least one spouse or child is required.', 400);
            $result = DB::connection()->transaction(function () use ($tree, $input, $marker): array {
                $spouses = [];
                foreach (['husband' => 'HUSB', 'wife' => 'WIFE'] as $keyName => $familyTag) {
                    if (!array_key_exists($keyName, $input)) continue;
                    $spouses[] = [$familyTag, $this->participant($tree, $input[$keyName], $keyName)];
                }
                $children = [];
                foreach (($input['children'] ?? []) as $child) $children[] = $this->participant($tree, $child, 'child');
                $familyGedcom = '0 @@ FAM';
                foreach ($spouses as [$tag, $participant]) $familyGedcom .= "\n1 {$tag} @{$participant['xref']}@";
                foreach ($children as $participant) $familyGedcom .= "\n1 CHIL @{$participant['xref']}@";
                $familyGedcom .= "\n" . $marker;
                if (($input['gedcom'] ?? '') !== '') $familyGedcom .= "\n" . trim((string) $input['gedcom']);
                if (($input['note'] ?? '') !== '') $familyGedcom .= "\n1 NOTE " . trim((string) $input['note']);
                $family = $tree->createRecord($familyGedcom);
                foreach ($spouses as [$tag, $participant]) $this->link($participant['record'], 'FAMS', $family->xref());
                foreach ($children as $participant) $this->link($participant['record'], 'FAMC', $family->xref());
                return ['family-xref' => $family->xref(), 'record-xrefs' => array_values(array_unique(array_merge(array_map(static fn (array $v): string => $v[1]['xref'], $spouses), array_map(static fn (array $v): string => $v['xref'], $children))))];
            });
            return api_response($result + ['idempotent' => false, 'pending' => true], 202);
        } catch (\Throwable $exception) {
            return api_response($exception->getMessage(), 400);
        }
    }

    /** @return array{xref:string,record:Individual} */
    private function participant(object $tree, mixed $value, string $role): array
    {
        if (is_string($value) && $value !== '') {
            $record = \Fisharebest\Webtrees\Registry::gedcomRecordFactory()->make($value, $tree);
            if (!$record instanceof Individual) throw new \DomainException($role . ' must identify an INDI record.', 400);
            return ['xref' => $record->xref(), 'record' => $record];
        }
        if (!is_array($value)) throw new \DomainException($role . ' must be an XREF or participant object.', 400);
        $gedcom = trim((string) ($value['gedcom'] ?? ''));
        if ($gedcom === '') $gedcom = '1 NAME ' . trim((string) ($value['name'] ?? ''));
        if (!str_contains($gedcom, '1 NAME ') && ($value['name'] ?? '') !== '') $gedcom = "1 NAME {$value['name']}\n" . $gedcom;
        $record = $tree->createRecord("0 @@ INDI\n{$gedcom}" . (($value['note'] ?? '') !== '' ? "\n1 NOTE {$value['note']}" : ''));
        return ['xref' => $record->xref(), 'record' => $record];
    }

    private function link(Individual $record, string $tag, string $xref): void
    {
        if (preg_match('/^1 ' . $tag . ' @' . preg_quote($xref, '/') . '@$/m', $record->gedcom()) === 1) return;
        $record->updateRecord(trim($record->gedcom()) . "\n1 {$tag} @{$xref}@", false);
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_ADD_FAMILY, 'description' => 'Create a family with spouses, children, links and notes atomically in one pending transaction.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'idempotency-key' => ['type' => 'string'], 'husband' => ['type' => ['string', 'object']], 'wife' => ['type' => ['string', 'object']], 'children' => ['type' => 'array'], 'gedcom' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['tree', 'idempotency-key']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_ADD_FAMILY, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
