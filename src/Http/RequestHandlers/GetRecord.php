<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2026 webtrees development team
 *                    <http://webtrees.net>
 *
 * CustomModuleManager (webtrees custom module):
 * Copyright (C) 2026 Markus Hemprich
 *                    <http://www.familienforschung-hemprich.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *
 * webtrees API
 *
 * A webtrees(https://webtrees.net) 2.2 custom module to provide an API for webtrees
 *
 */


declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Factories\GedcomRecordFactory;
use Gedcom\GedcomX\Generator;
use Jefferson49\Webtrees\Authorization\Auth;
use Jefferson49\Webtrees\Helpers\Authorization;
use Jefferson49\Webtrees\Helpers\Functions;
use Jefferson49\Webtrees\Module\WebtreesApi\GedcomX\StringParser;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Parameter\GedcomFormat as GedcomFormatParameter;
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
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;


class GetRecord implements WebtreesMcpToolRequestHandlerInterface
{
    private TreeService $tree_service;

    // Annotations
    public const string METHOD_DESCRIPTION = 'Retrieve the GEDCOM data for a record.';

    public function __construct(TreeService $tree_service)
    {
        $this->tree_service = $tree_service;
    }

    #[OA\Get(
        path: '/' . WebtreesApi::PATH_GET_RECORD,
        description: self::METHOD_DESCRIPTION,
        tags: ['webtrees'],
        parameters: [
            new OA\Parameter(
                ref: TreeParameter::class,
                required: true,
            ),
            new OA\Parameter(
                name: 'xref',
                in: 'query',
                description: 'The XREF (i.e. GEDOM cross-reference identifier) of the record to retrieve.',
                required: true,
                schema: new OA\Schema(
                    ref: XrefSchema::class,
                ),
            ),
            new OA\Parameter(
                ref: GedcomFormatParameter::class,
                required: true,
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The GEDCOM data of a record in webtrees',
                content: [
                    new OA\JsonContent(
                        type: 'object',
                        description: 'The GEDCOM-X data of a record in webtrees',
                        example:
                            ['persons' => [[
                                'id' => 'X1234',
                                'names' => [[
                                    'nameForms' => [[
                                        'fullText' => 'John Doe',
                                    ]]]],
                                'facts' => [[
                                    'type' => 'http://gedcomx.org/Birth',
                                    'date' => [
                                        'original' => '19 FEB 1870',
                            ]]]]]],
                    ),
                    new OA\MediaType(
                        mediaType: 'application/text',
                        schema: new OA\Schema(
                            type: 'string',
                            description: 'The GEDCOM 5.5.1 data of a record in webtrees',
                            example:
                                "0 @X1234@ INDI\n".
                                "1 NAME John /Doe/\n".
                                "1 BIRT\n".
                                "2 DATE 19 FEB 1870\n"
                        ),
                    ),
                ],
            ),
            new OA\Response(
                response: '400',
                description: 'Bad request: Validation of input parameters failed.',
                ref: Response400::class,
            ),
            new OA\Response(
                response: '401',
                description: 'Unauthorized: Missing authorization header or bearer token.',
                ref: Response401::class,
            ),
            new OA\Response(
                response: '403',
                description: 'Unauthorized: Insufficient permissions.',
                ref: Response403::class,
            ),
            new OA\Response(
                response: '404',
                description: 'Not found: Tree does not exist, or no matching GEDCOM record found for XREF.',
                ref: Response404::class,
            ),
            new OA\Response(
                response: '406',
                description: 'Not acceptable',
                ref: Response406::class,
            ),
            new OA\Response(
                response: '429',
                description: 'Too many requests',
                ref: Response429::class,
            ),
            new OA\Response(
                response: '500',
                description: 'Internal server error',
                ref: Response500::class,
            ),
        ],
    )]
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface {
        try {
            return $this->getRecord($request);
        }
        catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

	/**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function getRecord(ServerRequestInterface $request): ResponseInterface
    {
        $tree_name = Validator::queryParams($request)->string('tree', '');
        $xref      = Validator::queryParams($request)->string('xref', '');
        $format    = Validator::queryParams($request)->string('format', GedcomFormatParameter::DEFAULT_VALUE);

        // Validate tree
        $tree_validation_response = QueryParamValidator::validateTreeName($this->tree_service, $tree_name);
        if ($tree_validation_response->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
            return $tree_validation_response;
        }

        $tree = $this->tree_service->all()[$tree_name];

        // Resolve member access using only the scope belonging to this transport.
        $privacy_validation_response = ReadAccess::validateTree($request, $tree);
        if ($privacy_validation_response->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
            return $privacy_validation_response;
        }
        $access_level = ReadAccess::accessLevel($request, $tree);

        // Validate xref
        $xref_validation_response = QueryParamValidator::validateXref($tree, $xref);
        if ($xref_validation_response->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
            return $xref_validation_response;
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);

        //Validate record access
        $xref_validation_response = CheckAccess::checkRecordAccess($record, false, $access_level === Auth::PRIV_PRIVATE);
        if ($xref_validation_response->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
            return $xref_validation_response;
        }

        // Validate format
        $format_validation_response = QueryParamValidator::validateGedcomFormat($format);
        if ($format_validation_response->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
            return $format_validation_response;
        }

        //Validate record type and format
        if (!in_array($record->tag(), ['INDI', 'FAM']) && in_array($format, [GedcomFormatParameter::FORMAT_GEDCOM_X, GedcomFormatParameter::FORMAT_JSON])) {
            return api_response('Invalid format parameter for record type: "gedxom-x" and "json" are only supported for INDI and FAM records.', StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        // Create GEDCOM
        $gedcom = Functions::getPrivatizedGedcom($record, $access_level) . "\n";

        if ($format === GedcomFormatParameter::FORMAT_GEDCOM_RECORD) {
            return api_response($gedcom, StatusCodeInterface::STATUS_OK);
        }

        $gedcom  = self::getGedcomHeader() . $gedcom;
        $gedcom .= self::getGedcomOfLinkedRecords(
            $tree,
            $gedcom,
            [$record->xref()],
            $access_level,
            ReadAccess::isMcp($request) && ReadAccess::hasMemberScope($request),
        );
        $gedcom .= "0 TRLR\n";

        if ($format === GedcomFormatParameter::FORMAT_GEDCOM) {
            return api_response($gedcom, StatusCodeInterface::STATUS_OK);
        }
        elseif (in_array($format, [GedcomFormatParameter::FORMAT_GEDCOM_X, GedcomFormatParameter::FORMAT_JSON])) {
            $parser = new StringParser();
            $gedcom_object = $parser->parse($gedcom);
            $generator = new Generator($gedcom_object);
            $gedcom_x_json = $generator->generate();
            $gedcom_x_json = self::substituteXREFs($generator, $gedcom_x_json);

            return api_response($gedcom_x_json, StatusCodeInterface::STATUS_OK, ['content-type' => 'application/json']);
        }
        else {
            return api_response('Invalid format parameter', StatusCodeInterface::STATUS_BAD_REQUEST);
        }
    }

    /**
     * The tool description for the MCP protocol provided as an array (which can be converted to JSON)
     *
     * @return string
     */
    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_GET_RECORD,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tree' => McpSchema::TREE,
                    'xref' => McpSchema::withDescription(McpSchema::XREF,
                        'The XREF (i.e. GEDOM cross-reference identifier) of the record to retrieve.',
                        McpSchema::APPEND
                    ),
                    'format' => McpSchema::GEDCOM_FORMAT,
                ],
                'required' => ['tree', 'xref']
            ],
            'outputSchema' => [
                'type' => 'object',
            ],
            'annotations' => [
                'title' => WebtreesApi::PATH_GET_RECORD,
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
                'deprecated' => false
            ],
        ];
    }

    /**
     * Get a GEDCOM string, which includes the combined GEDCOM strings of all records linked (by XREF)
     *
     * @param Tree     $tree
     * @param string   $gedcom
     * @param array    $excluded_xrefs
     * @param int|null $access_level    // defined in: Auth
     *
     * @return string
     */
    public static function getGedcomOfLinkedRecords(
        Tree $tree,
        string $gedcom,
        array $excluded_xrefs = [],
        int|null $access_level = null,
        bool $include_full_records = false,
    ): string {
        $access_level ??= Authorization::accessLevelForTree($tree);
        $seen = array_fill_keys($excluded_xrefs, true);

        return self::collectLinkedRecords($tree, $gedcom, $seen, $access_level, $include_full_records);
    }

    /**
     * Recursively serialize only records that the selected access level can show.
     * Member reads retain complete privatized GEDCOM; privacy-only reads retain
     * the historical compact linked-person representation.
     *
     * @param array<string, bool> $seen
     */
    private static function collectLinkedRecords(
        Tree $tree,
        string $gedcom,
        array &$seen,
        int $access_level,
        bool $include_full_records,
    ): string {
        $linked_records_gedcom = '';
        $gedcom_factory = new GedcomRecordFactory();
        preg_match_all('/@(' . Gedcom::REGEX_XREF . ')@/', $gedcom, $matches);

        foreach (array_unique($matches[1] ?? []) as $xref) {
            if (isset($seen[$xref])) {
                continue;
            }
            $seen[$xref] = true;

            $record = $gedcom_factory->make($xref, $tree);
            if ($record === null) {
                continue;
            }

            $privacy = $access_level === Auth::PRIV_PRIVATE;
            if (CheckAccess::checkRecordAccess($record, false, $privacy)->getStatusCode() !== StatusCodeInterface::STATUS_OK) {
                continue;
            }

            $record_tag = $record->tag();
            $privatized_gedcom = trim(Functions::getPrivatizedGedcom($record, $access_level));
            if ($privatized_gedcom === '') {
                continue;
            }

            if ($include_full_records) {
                $linked_records_gedcom .= $privatized_gedcom . "\n";
                $linked_records_gedcom .= self::collectLinkedRecords($tree, $privatized_gedcom, $seen, $access_level, true);
                continue;
            }

            switch ($record_tag) {
                case 'INDI':
                    $linked_records_gedcom .= "0 @" . $xref . "@ INDI\n";
                    foreach (['NAME'] as $tag) {
                        preg_match_all('/^1 ' . $tag . ' (.*)$/m', $privatized_gedcom, $tag_matches);
                        foreach ($tag_matches[1] ?? [] as $payload) {
                            $linked_records_gedcom .= '1 ' . $tag . ' ' . $payload . "\n";
                        }
                    }
                    foreach (['BIRT', 'DEAT'] as $tag) {
                        preg_match_all('/1 ' . $tag . ".*?\n2 DATE ([^\n]*)\n/s", $privatized_gedcom, $tag_matches);
                        foreach ($tag_matches[1] ?? [] as $payload) {
                            $linked_records_gedcom .= '1 ' . $tag . "\n2 DATE " . $payload . "\n";
                        }
                    }
                    break;
                case 'FAM':
                    $linked_records_gedcom .= $privatized_gedcom . "\n";
                    $linked_records_gedcom .= self::collectLinkedRecords($tree, $privatized_gedcom, $seen, $access_level, false);
                    break;
                case 'NOTE':
                    preg_match('/^0 @' . preg_quote($xref, '/') . '@ NOTE .*$/m', $privatized_gedcom, $note_match);
                    if (isset($note_match[0])) {
                        $linked_records_gedcom .= $note_match[0] . "\n";
                    }
                    break;
                case 'OBJE':
                    $linked_records_gedcom .= "0 @" . $xref . "@ OBJE\n";
                    preg_match_all('/^1 FILE .*?(?=\n1 |\z)/ms', $privatized_gedcom, $file_matches);
                    foreach ($file_matches[0] ?? [] as $file) {
                        $linked_records_gedcom .= trim($file) . "\n";
                    }
                    break;
                case 'SOUR':
                    $linked_records_gedcom .= "0 @" . $xref . "@ SOUR\n";
                    preg_match_all('/^1 TITL .*$/m', $privatized_gedcom, $title_matches);
                    foreach ($title_matches[0] ?? [] as $title) {
                        $linked_records_gedcom .= $title . "\n";
                    }
                    break;
                case 'REPO':
                case '_LOC':
                    $linked_records_gedcom .= '0 @' . $xref . '@ ' . $record_tag . "\n";
                    preg_match_all('/^1 NAME .*$/m', $privatized_gedcom, $name_matches);
                    foreach ($name_matches[0] ?? [] as $name) {
                        $linked_records_gedcom .= $name . "\n";
                    }
                    break;
                default:
                    $linked_records_gedcom .= '0 @' . $xref . '@ ' . $record_tag . "\n";
            }
        }

        return $linked_records_gedcom;
    }

	/**
     * Get a GEDCOM string, which includes the combined GEDCOM strings of all records linked (by XREF)
     *
     * @param Generator $generator  The GEDCOM-X generator
     * @param string    $gedcom
     *
     * @return string
     */
    public static function substituteXREFs(Generator $generator, string $gedcom): string {

        // Create Reflection structure
        $reflection  = new ReflectionClass('\\Gedcom\\GedcomX\\Generator');
        $personIdMap  = $reflection->getProperty('personIdMap');
        $relationshipIdMap  = $reflection->getProperty('relationshipIdMap');

        $xrefs = array_merge($personIdMap->getValue($generator), $relationshipIdMap->getValue($generator));

        foreach ($xrefs as $replace => $search) {

            $replace = str_replace('_couple', '', $replace);
            $gedcom = str_replace($search, $replace, $gedcom);
        }

        return $gedcom;
    }

	/**
     * Create a GEDCOM header
     *
     * @return string
     */
    public static function getGedcomHeader(): string {

        return
            "0 HEAD\n".
            "1 SOUR webtrees\n".
            "1 CHAR UTF-8\n".
            "1 GEDC\n".
            "2 VERS 5.5.1\n".
            "2 FORM LINEAGE-LINKED\n";
    }
}
