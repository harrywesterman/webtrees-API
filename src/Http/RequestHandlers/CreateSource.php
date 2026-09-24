<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CreateSource implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private TreeService $tree_service) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = $request->getQueryParams();
        $gedcom = (string) ($input['gedcom'] ?? '');
        foreach (['title' => 'TITL', 'author' => 'AUTH', 'publication' => 'PUBL'] as $key => $tag) {
            if (isset($input[$key]) && $input[$key] !== '') $gedcom .= "\n1 {$tag} " . $input[$key];
        }
        if (isset($input['note']) && $input['note'] !== '') $gedcom .= "\n1 NOTE " . $input['note'];
        return (new AddUnlinkedRecord($this->tree_service))->handle($request->withQueryParams(array_replace($input, ['record-type' => 'SOUR', 'gedcom' => trim($gedcom), 'note' => ''])));
    }
    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_CREATE_SOURCE, 'description' => 'Create a first-class SOUR record with optional title, author, publication and note.', 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => ['type' => 'string'], 'title' => ['type' => 'string'], 'author' => ['type' => 'string'], 'publication' => ['type' => 'string'], 'note' => ['type' => 'string'], 'gedcom' => ['type' => 'string']], 'required' => ['tree', 'title']], 'outputSchema' => ['type' => 'object', 'properties' => ['xref' => ['type' => 'string']], 'required' => ['xref']], 'annotations' => ['title' => WebtreesApi::PATH_CREATE_SOURCE, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]];
    }
}
