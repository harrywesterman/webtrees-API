<?php

/** Load actual webtrees classes and verify transport/discovery contracts without a site/database. */
declare(strict_types=1);

$root = getenv('WEBTREES_TEST_ROOT');
if (!$root) { throw new RuntimeException('Set WEBTREES_TEST_ROOT to an unpacked webtrees release.'); }
require $root . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\Media;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\MediaDownload;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\MediaLinks;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\McpToolPermission;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\ProcessApi;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\ApiPermission;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\WebtreesMcpToolRequestHandlerInterface;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\GetRecord;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\ModifyRecord;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\ReadAccess;
use Fisharebest\Webtrees\Registry;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

$checks = 0;
Registry::container(new Fisharebest\Webtrees\Container());
Registry::container()->set(Aura\Router\RouterContainer::class, new Aura\Router\RouterContainer());
Registry::routeFactory(new Fisharebest\Webtrees\Factories\RouteFactory());
function check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}

foreach ([Fisharebest\Webtrees\Tree::class => ['mediaFilesystem', 'createRecord'],
    Fisharebest\Webtrees\GedcomRecord::class => ['updateRecord', 'deleteRecord', 'isPendingAddition', 'isPendingDeletion'],
    Fisharebest\Webtrees\MediaFile::class => ['filename', 'title'],
    Jefferson49\Webtrees\Helpers\Functions::class => ['registerRoute', 'getRecordFacts']] as $class => $methods) {
    foreach ($methods as $method) { check(method_exists($class, $method), $class . '::' . $method); }
}
check(Fisharebest\Webtrees\Media::RECORD_TYPE === 'OBJE', 'GEDCOM record type');
new McpToolPermission();
$combined = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_read_privacy', 'api_read_member'])
    ->withAttribute('webtrees_api_transport', ReadAccess::TRANSPORT_MCP);
check(!ReadAccess::hasMemberScope($combined), 'MCP ignores api_read_member');
check(ReadAccess::hasMemberScope($combined->withAttribute('oauth_scopes', ['mcp_read_member'])), 'MCP member scope');
check(ReadAccess::hasMemberScope($combined->withAttribute('webtrees_api_transport', ReadAccess::TRANSPORT_API)), 'API member scope');
check(!ReadAccess::hasMemberScope($combined->withAttribute('oauth_scopes', ['mcp_read_privacy'])), 'Privacy-only scope');
check(MediaInput::MCP_INLINE_LIMIT === 512 * 1024, 'Inline MCP limit');
check(MediaInput::maxBase64Length() === 699052, 'Inline base64 limit');
$getRecordMethod = (new ReflectionClass(GetRecord::class))->getMethod('getGedcomOfLinkedRecords');
check($getRecordMethod->getNumberOfParameters() === 5, 'Full linked-record mode');
$guardMethod = (new ReflectionClass(ModifyRecord::class))->getMethod('removedProtectedLinks');
$removed = $guardMethod->invoke(null, "0 @I1@ INDI\n1 FAMS @F1@\n1 OBJE @M1@", "0 @I1@ INDI\n1 NAME Test");
check($removed === ['FAMS @F1@', 'OBJE @M1@'], 'Protected link guard');
$mcpToolSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/McpTool.php');
check(str_contains($mcpToolSource, 'ReadAccess::TRANSPORT_MCP'), 'MCP transport marker');

$settingsSource = file_get_contents(__DIR__ . '/../resources/views/settings.phtml');
check(str_contains($settingsSource, "'token_scopes'         => $client_scopes,"), 'Token form passes client scope identifiers');
$tokenModalSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/CreateTokenModal.php');
check(str_contains($tokenModalSource, '$token_scope_identifiers'), 'Token modal reads scope identifiers');
check(str_contains($tokenModalSource, 'getScopesForIdentifiers($token_scope_identifiers)'), 'Token modal resolves scope identifiers');
$tokenActionSource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/CreateTokenAction.php');
check(str_contains($tokenActionSource, 'if (empty($token_scopes))'), 'Token creation rejects empty scopes');
$seen = [];
foreach (['UploadMedia', 'GetMedia', 'UpdateMedia', 'LinkMedia', 'UnlinkMedia', 'DeleteMedia'] as $short) {
    $class = 'Jefferson49\\Webtrees\\Module\\WebtreesApi\\Http\\RequestHandlers\\' . $short;
    $reflection = new ReflectionClass($class);
    check($reflection->implementsInterface(WebtreesMcpToolRequestHandlerInterface::class), 'Discoverable ' . $short);
    $tool = $class::getMcpToolDescription();
    $seen[] = $tool['name'];
    check(in_array($tool['name'], $short === 'GetMedia' ? McpToolPermission::$mcp_read_tools : McpToolPermission::$mcp_write_tools, true), 'Tool scope ' . $short);
}
check($seen === MediaTools::ACTIONS, 'All six tools discovered once');

$protocolClass = new ReflectionClass(Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\McpProtocol::class);
$protocol = $protocolClass->newInstanceWithoutConstructor();
$tools = $protocolClass->getMethod('getTools')->invoke($protocol, WebtreesMcpToolRequestHandlerInterface::class);
foreach (MediaTools::ACTIONS as $action) {
    check(count(array_filter($tools, fn ($tool) => $tool['name'] === $action)) === 1, 'Actual tools/list discovers ' . $action);
}
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
Registry::responseFactory(new Fisharebest\Webtrees\Factories\ResponseFactory($factory, $factory));
$protocolClass->getProperty('stream_factory')->setValue(null, $factory);
Registry::container()->set(Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi::class,
    new class extends Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi {
        public function debuggingActivated(): bool { return false; }
    });
Registry::container()->set(Media::class, new class extends Media {
    public function __construct() {}
    public function execute(Psr\Http\Message\ServerRequestInterface $request, string $action): Psr\Http\Message\ResponseInterface {
        return new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'action' => $action, 'mcp' => $request->getAttribute('media_mcp'), 'arguments' => $request->getQueryParams(),
        ]));
    }
});
$dispatcher = new Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\McpTool($factory, $factory,
    (new ReflectionClass(Fisharebest\Webtrees\Services\ModuleService::class))->newInstanceWithoutConstructor());
foreach (MediaTools::ACTIONS as $action) {
    $request = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_write'])
        ->withAttribute('mcp_tool_interface', WebtreesMcpToolRequestHandlerInterface::class)
        ->withParsedBody(['id' => 1, 'name' => $action, 'arguments' => ['tree' => 'test', 'xref' => 'M1']]);
    $result = json_decode((string) $dispatcher->handle($request)->getBody(), true);
    check(($result['result']['isError'] ?? true) === false, '202 is MCP success ' . $action);
    check(($result['result']['structuredContent']['action'] ?? '') === $action && $result['result']['structuredContent']['mcp'] === true, 'Dispatch ' . $action);
}

$next = new class implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface {
        return new Response(200, [], $request->getAttribute('media_http_method', $request->getMethod()));
    }
};
foreach (MediaTools::ACTIONS as $action) {
    $read = $action === 'get-media';
    $request = (new ServerRequest('GET', ''))->withParsedBody(['id' => 1, 'name' => $action])
        ->withAttribute('oauth_scopes', [$read ? 'mcp_read_member' : 'mcp_write']);
    check((new McpToolPermission())->process($request, $next)->getStatusCode() === 200, 'Allowed scope ' . $action);
    $denied = $request->withAttribute('oauth_scopes', [$read ? 'mcp_write' : 'mcp_read_member']);
    check((new McpToolPermission())->process($denied, $next)->getStatusCode() === 403, 'Denied scope ' . $action);
}
// The legacy middleware must preserve methods and multipart for each media controller.
foreach ([Media::class => ['GET', 'POST', 'PUT', 'DELETE'], MediaDownload::class => ['GET'], MediaLinks::class => ['POST', 'DELETE']] as $class => $methods) {
    $map = Registry::routeFactory()->routeMap();
    $route = $map->get($class, '/test/' . count($methods), $class)->allows($methods);
    foreach ($methods as $method) {
        $request = (new ServerRequest($method, ''))->withAttribute('route', $route)->withAttribute('oauth_scopes', [])
            ->withParsedBody(['tree' => 'multipart-tree'])
            ->withUploadedFiles(['file' => new Nyholm\Psr7\UploadedFile('image-bytes', 11, UPLOAD_ERR_OK, 'test.png', 'image/png')]);
        $afterCsrf = new class($next) implements Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private Psr\Http\Server\RequestHandlerInterface $next) {}
            public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface {
                check($request->getMethod() === 'GET', 'Internal OAuth request bypasses browser CSRF');
                check($request->getParsedBody()['tree'] === 'multipart-tree' && $request->getUploadedFiles()['file']->getClientFilename() === 'test.png', 'Multipart survives internal conversion');
                return (new Fisharebest\Webtrees\Http\Middleware\CheckCsrf())->process($request, $this->next);
            }
        };
        check((string) (new ProcessApi())->process($request, $afterCsrf)->getBody() === $method, 'Preserved method through real CSRF ' . $method);
        check((new ApiPermission())->process($request, $next)->getStatusCode() === 200, 'Controller reaches own scope check');
    }
}
foreach ([Media::class => 'upload-media', MediaLinks::class => 'link-media'] as $class => $action) {
    $media = Registry::container()->get(Media::class);
    $controller = $class === Media::class ? $media : new MediaLinks($media);
    $response = $controller->handle((new ServerRequest('GET', '/api/media'))->withAttribute('media_http_method', 'POST'));
    check(json_decode((string) $response->getBody(), true)['action'] === $action, 'Controller recovers original POST ' . $action);
}
check((new MediaDownload(Registry::container()->get(Media::class)))->handle((new ServerRequest('GET', ''))->withAttribute('media_http_method', 'POST'))->getStatusCode() === 405, 'Download refuses internally converted POST');
// Exercise the production route registration, not a test-only list of methods.
$source = file_get_contents(__DIR__ . '/../src/WebtreesApi.php');
foreach ([Media::class => ['GET', 'POST', 'PUT', 'DELETE'], MediaLinks::class => ['POST', 'DELETE']] as $class => $methods) {
    $short = (new ReflectionClass($class))->getShortName();
    preg_match('/getRoute\\(' . $short . '::class\\)->allows\\(([^;]+)\\);/', $source, $match);
    preg_match_all("/'([A-Z]+)'/", $match[1] ?? '', $allowed);
    check($allowed[1] === $methods, 'Production route methods ' . $short);
}
$processMcp = new Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\ProcessMcp();
$limit = $processMcp::bodyLimit();
foreach ([['Content-Length' => (string) ($limit + 1)], []] as $headers) {
    $body = $headers ? '' : str_repeat(' ', $limit + 1);
    $response = $processMcp->process(new ServerRequest('POST', '/mcp', $headers, $body), $next);
    $error = json_decode((string) $response->getBody(), true);
    check($response->getStatusCode() === 413 && $error['id'] === null && $error['error']['data']['maxBodyBytes'] === $limit, 'Oversized MCP including discarded/missing length');
}
$truncated = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Content-Length' => '3000000'], str_repeat(' ', 2999999));
$truncatedResponse = $processMcp->process($truncated, $next);
$truncatedError = json_decode((string) $truncatedResponse->getBody(), true);
check($truncatedResponse->getStatusCode() === 413 && $truncatedError['error']['data']['receivedBodyBytes'] === 2999999, 'Upstream-truncated MCP body');
$unterminated = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"padding":"' . str_repeat('A', 2 * 1024 * 1024) );
$unterminatedResponse = $processMcp->process($unterminated, $next);
check($unterminatedResponse->getStatusCode() === 413, 'Large unterminated MCP body');
$body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
$body .= str_repeat(' ', $limit - strlen($body));
check($processMcp->process(new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $body), $next)->getStatusCode() === 200, 'Exact transport boundary allowed');
check(str_contains(MediaTools::description('get-media')['description'], '/api/media/download'), 'Download tool path');
check(str_contains(MediaTools::description('get-media')['description'], 'mcp_read_member'), 'Pending read scope documented');
check(str_contains(MediaTools::description('upload-media')['description'], '512 KiB'), 'Inline upload limit documented');
check(str_contains(MediaTools::description('upload-media')['description'], 'multipart REST POST /api/media'), 'Multipart upload documented');
$rpc413 = json_decode((string) Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\McpProtocol::toolResult(
    1,
    new Response(413, ['Content-Type' => 'application/json'], json_encode([
        'error' => 'inline_upload_too_large',
        'maxInlineBytes' => MediaInput::MCP_INLINE_LIMIT,
        'multipartEndpoint' => '/api/media',
        'requiredScope' => 'api_write',
    ])),
), true, 512, JSON_THROW_ON_ERROR);
check(($rpc413['result']['isError'] ?? false) === true && ($rpc413['result']['structuredContent']['maxInlineBytes'] ?? 0) === 512 * 1024, 'JSON-RPC 413 handoff');
$json = json_decode(file_get_contents(__DIR__ . '/../resources/OpenApi/OpenApi.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (MediaTools::openApiPaths() as $path => $schema) { check(($json['paths'][$path] ?? null) === $schema, 'Generated OpenAPI matches ' . $path); }
echo "PASS: $checks real-webtrees API/transport/schema contracts.\n";
