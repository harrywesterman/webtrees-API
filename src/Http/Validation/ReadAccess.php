<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Authorization\Auth;
use Jefferson49\Webtrees\Helpers\Authorization;
use Jefferson49\Webtrees\Module\WebtreesApi\OAuth2\Repositories\ScopeRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/** Resolve read access without allowing API scopes to elevate MCP requests. */
final class ReadAccess
{
    public const string TRANSPORT_API = 'api';
    public const string TRANSPORT_MCP = 'mcp';

    public static function isMcp(ServerRequestInterface $request): bool
    {
        return Validator::attributes($request)->string('webtrees_api_transport', self::TRANSPORT_API) === self::TRANSPORT_MCP;
    }

    public static function hasMemberScope(ServerRequestInterface $request): bool
    {
        $scopes = Validator::attributes($request)->array('oauth_scopes');
        $scope = self::isMcp($request)
            ? ScopeRepository::SCOPE_MCP_READ_MEMBER
            : ScopeRepository::SCOPE_API_READ_MEMBER;

        return in_array($scope, $scopes, true);
    }

    public static function accessLevel(ServerRequestInterface $request, Tree $tree): int
    {
        return self::hasMemberScope($request)
            ? Authorization::accessLevelForTree($tree)
            : Auth::PRIV_PRIVATE;
    }

    public static function validateTree(ServerRequestInterface $request, Tree $tree): ResponseInterface
    {
        return self::hasMemberScope($request)
            ? api_response('OK', 200)
            : CheckAccess::checkTreePrivacy($tree);
    }
}
