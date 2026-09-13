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
        return new Response(200, [], $request->getMethod());
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
        $request = (new ServerRequest($method, ''))->withAttribute('route', $route)->withAttribute('oauth_scopes', []);
        check((string) (new ProcessApi())->process($request, $next)->getBody() === $method, 'Preserved method ' . $method);
        check((new ApiPermission())->process($request, $next)->getStatusCode() === 200, 'Controller reaches own scope check');
    }
}
$json = json_decode(file_get_contents(__DIR__ . '/../resources/OpenApi/OpenApi.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (MediaTools::openApiPaths() as $path => $schema) { check(($json['paths'][$path] ?? null) === $schema, 'Generated OpenAPI matches ' . $path); }
echo "PASS: $checks real-webtrees API/transport/schema contracts.\n";
