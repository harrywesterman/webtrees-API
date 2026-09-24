<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class SourceContext
{
    public static function tree(TreeService $trees, ServerRequestInterface $request): Tree|ResponseInterface
    {
        $name = Validator::queryParams($request)->string('tree', '');
        $response = QueryParamValidator::validateTreeName($trees, $name);
        return $response->getStatusCode() === StatusCodeInterface::STATUS_OK ? $trees->all()[$name] : $response;
    }

    public static function record(Tree $tree, string $xref, bool $edit): GedcomRecord|ResponseInterface
    {
        $validation = QueryParamValidator::validateXref($tree, $xref);
        if ($validation->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $validation;
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) return api_response('Record not found', StatusCodeInterface::STATUS_NOT_FOUND);
        $access = CheckAccess::checkRecordAccess($record, $edit);
        return $access->getStatusCode() === StatusCodeInterface::STATUS_OK ? $record : $access;
    }

    public static function memberRequired(ServerRequestInterface $request): ?ResponseInterface
    {
        return ReadAccess::hasMemberScope($request) ? null : api_response('This source operation requires member read access.', StatusCodeInterface::STATUS_FORBIDDEN);
    }
}
