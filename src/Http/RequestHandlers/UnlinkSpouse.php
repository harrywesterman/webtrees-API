<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Helpers\RelationshipLinks;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Parameter\Tree as TreeParameter;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response400;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response401;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response403;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response404;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response406;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response429;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Response\Response500;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Xref as XrefSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\XrefItem;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class UnlinkSpouse implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Remove an individual from a family as a spouse, preserving both records.';
    public const string INDI_XREF_DESCRIPTION = 'The XREF of the spouse individual to unlink.';
    public const string FAM_XREF_DESCRIPTION = 'The XREF of the family from which the spouse shall be unlinked.';

    public function __construct(private TreeService $tree_service) {}

    #[OA\Delete(
        path: '/' . WebtreesApi::PATH_UNLINK_SPOUSE,
        tags: ['webtrees'],
        description: self::METHOD_DESCRIPTION,
        parameters: [
            new OA\Parameter(ref: TreeParameter::class, required: true),
            new OA\Parameter(name: 'individual-xref', in: 'query', description: self::INDI_XREF_DESCRIPTION, required: true, schema: new OA\Schema(ref: XrefSchema::class)),
            new OA\Parameter(name: 'family-xref', in: 'query', description: self::FAM_XREF_DESCRIPTION, required: true, schema: new OA\Schema(ref: XrefSchema::class)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Successfully unlinked spouse.', content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(ref: XrefItem::class))),
            new OA\Response(response: '400', description: 'Bad request: Validation of input parameters failed.', ref: Response400::class),
            new OA\Response(response: '401', description: 'Unauthorized: Missing authorization header or bearer token.', ref: Response401::class),
            new OA\Response(response: '403', description: 'Unauthorized: Insufficient permissions.', ref: Response403::class),
            new OA\Response(response: '404', description: 'Not found: Tree, individual, family, or relationship does not exist.', ref: Response404::class),
            new OA\Response(response: '406', description: 'Not acceptable.', ref: Response406::class),
            new OA\Response(response: '429', description: 'Too many requests.', ref: Response429::class),
            new OA\Response(response: '500', description: 'Internal server error.', ref: Response500::class),
        ]
    )]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->unlink($request);
        } catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function unlink(ServerRequestInterface $request): ResponseInterface
    {
        $tree_name = Validator::queryParams($request)->string('tree', '');
        $individual_xref = Validator::queryParams($request)->string('individual-xref', '');
        $family_xref = Validator::queryParams($request)->string('family-xref', '');

        $tree_validation = QueryParamValidator::validateTreeName($this->tree_service, $tree_name);
        if ($tree_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $tree_validation;
        $tree = $this->tree_service->all()[$tree_name];

        $individual_validation = QueryParamValidator::validateXref($tree, $individual_xref);
        if ($individual_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $individual_validation;
        $individual = Registry::individualFactory()->make($individual_xref, $tree);
        if ($individual === null) return api_response('Individual not found', StatusCodeInterface::STATUS_NOT_FOUND);

        $family_validation = QueryParamValidator::validateXref($tree, $family_xref);
        if ($family_validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $family_validation;
        $family = Registry::familyFactory()->make($family_xref, $tree);
        if ($family === null) return api_response('Family not found', StatusCodeInterface::STATUS_NOT_FOUND);

        foreach ([$individual, $family] as $record) {
            $access = CheckAccess::checkRecordAccess($record, true);
            if ($access->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $access;
        }
        $write = CheckAccess::checkUserWriteAccess($tree);
        if ($write->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $write;

        try {
            $individual = Auth::checkIndividualAccess($individual, true);
            $family = Auth::checkFamilyAccess($family, true);
        } catch (HttpNotFoundException | HttpAccessDeniedException) {
            return api_response('Insufficient permissions: No access to the relationship records.', StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $removed = RelationshipLinks::removeFactsTo($individual, ['FAMS'], $family);
        $removed += RelationshipLinks::removeFactsTo($family, ['HUSB', 'WIFE'], $individual);
        if ($removed === 0) return api_response('Spouse link not found', StatusCodeInterface::STATUS_NOT_FOUND);

        return api_response(new XrefItem($individual->xref()), StatusCodeInterface::STATUS_OK);
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_UNLINK_SPOUSE,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => McpSchema::TREE,
                'individual-xref' => McpSchema::withDescription(McpSchema::XREF, self::INDI_XREF_DESCRIPTION, McpSchema::APPEND),
                'family-xref' => McpSchema::withDescription(McpSchema::XREF, self::FAM_XREF_DESCRIPTION, McpSchema::APPEND),
            ], 'required' => ['individual-xref', 'family-xref']],
            'outputSchema' => ['type' => 'object', 'properties' => ['xref' => McpSchema::XREF], 'required' => ['xref']],
            'annotations' => ['title' => WebtreesApi::PATH_UNLINK_SPOUSE, 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => true, 'deprecated' => false],
        ];
    }
}
