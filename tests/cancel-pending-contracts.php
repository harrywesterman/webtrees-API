<?php

/**
 * cancel-pending: a rejection that happened must not be reported as a failed
 * request, and a 500 must carry the handler's explanation to the client.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees {
    class Auth {
        public const PRIV_PRIVATE = 2, PRIV_USER = 1, PRIV_NONE = 0, PRIV_HIDE = -1;
        public static function user() { return new class { public function getPreference($key) { return '0'; } }; }
    }
    class Registry {
        public static array $records = [];
        public static $response;
        public static function responseFactory() { return self::$response; }
        public static function gedcomRecordFactory() { return new class {
            public function make($xref, $tree) { return Registry::$records[$xref] ?? null; }
        }; }
        /** webtrees 2.2 caches expose remember()/forget() only - no clear(). */
        public static function cache() { return new class {
            public function array() { return new class {
                public function remember($key, $closure, $ttl = null) { return $closure(); }
                public function forget($key): void {}
            }; }
        }; }
        public static function container() { return new class { public function get($class) { return new \stdClass(); } }; }
    }
}
namespace Fisharebest\Webtrees\Services {
    use Fisharebest\Webtrees\DB;

    class TreeService {
        public function __construct(private $tree) {}
        public function all() { return new \Illuminate\Support\Collection(['test' => $this->tree]); }
    }
    /** Mirrors PendingChangesService: rejection only flips the status. */
    class PendingChangesService {
        public function rejectRecord($record): void {
            DB::table('change')->where('gedcom_id', $record->tree()->id())->where('xref', $record->xref())
                ->where('status', 'pending')->update(['status' => 'rejected']);
        }
        public function rejectChange($record, $change_id): void {
            DB::table('change')->where('gedcom_id', $record->tree()->id())->where('xref', $record->xref())
                ->where('change_id', '<=', $change_id)->where('status', 'pending')->update(['status' => 'rejected']);
        }
    }
}
namespace Jefferson49\Webtrees\Helpers {
    class Functions { public static function getFromContainer($class) { return new \stdClass(); } }
}
namespace Jefferson49\Webtrees\Log {
    class CustomModuleLog { public static function addDebugLog(...$args) {} }
}
namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation {
    class CheckAccess {
        public static function checkRecordAccess($record, bool $edit = false, bool $privacy = false) { return \Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response('OK', 200); }
        public static function checkUserWriteAccess($tree) { return \Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response('OK', 200); }
    }
}
namespace {
    use Fisharebest\Webtrees\DB;
    use Fisharebest\Webtrees\GedcomRecord;
    use Fisharebest\Webtrees\Registry;
    use Fisharebest\Webtrees\Tree;
    use Fisharebest\Webtrees\Services\TreeService;
    use Fisharebest\Webtrees\Services\PendingChangesService;
    use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CancelPending;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\McpProtocol;
    use Nyholm\Psr7\Response;
    use Nyholm\Psr7\ServerRequest;

    $root = getenv('WEBTREES_TEST_ROOT');
    if (!$root) throw new RuntimeException('Set WEBTREES_TEST_ROOT to an unpacked webtrees release.');
    require $root . '/vendor/autoload.php';
    require __DIR__ . '/../autoload.php';
    $common = new Composer\Autoload\ClassLoader();
    foreach (['Helpers', 'Module', 'Exceptions', 'Log'] as $ns) {
        $common->addPsr4('Jefferson49\\Webtrees\\' . $ns . '\\', __DIR__ . '/../vendor/jefferson49/webtrees-common/' . $ns);
    }
    $common->register(true);

    $factory = new Nyholm\Psr7\Factory\Psr17Factory();
    Registry::$response = new Fisharebest\Webtrees\Factories\ResponseFactory($factory, $factory);
    (new ReflectionClass(McpProtocol::class))->getProperty('stream_factory')->setValue(null, $factory);

    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->setAsGlobal();
    DB::schema()->create('gedcom', function ($t) { $t->integer('gedcom_id'); });
    DB::table('gedcom')->insert(['gedcom_id' => 1]);
    foreach (['individuals' => 'i', 'families' => 'f', 'sources' => 's', 'media' => 'm', 'other' => 'o'] as $table => $p) {
        DB::schema()->create($table, function ($t) use ($p) { $t->integer($p . '_file'); $t->string($p . '_id'); $t->text($p . '_gedcom'); });
    }
    DB::schema()->create('change', function ($t) {
        $t->increments('change_id'); $t->integer('gedcom_id'); $t->integer('user_id')->nullable();
        $t->string('xref'); $t->string('status'); $t->text('old_gedcom'); $t->text('new_gedcom');
        $t->string('change_time')->default('2026-10-17 09:00:00');
    });
    DB::schema()->create('user', function ($t) { $t->integer('user_id'); $t->string('user_name'); $t->string('real_name'); });
    DB::table('user')->insert(['user_id' => 1, 'user_name' => 'editor', 'real_name' => 'Test Editor']);

    $tree = new Tree(1, 'test', 'Test', '', '', true, false, null, null);
    $trees = new TreeService($tree);
    $cancel = new CancelPending($trees, new PendingChangesService());

    $checks = 0;
    function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

    // The real webtrees cache has no clear(): the removed line threw an Error
    // after the rejection had already been committed.
    check(!method_exists(Fisharebest\Webtrees\Cache::class, 'clear'), 'webtrees Cache exposes no clear() method');

    $created = "0 @X1@ SOUR\n1 TITL Akte";
    DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'X1', 'user_id' => 1, 'status' => 'pending', 'old_gedcom' => '', 'new_gedcom' => $created]);
    DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'X1', 'user_id' => 1, 'status' => 'pending', 'old_gedcom' => $created, 'new_gedcom' => $created . "\n1 AUTH Test"]);
    Registry::$records['X1'] = new GedcomRecord('X1', $created, null, $tree);

    $response = $cancel->handle((new ServerRequest('DELETE', ''))->withQueryParams(['tree' => 'test', 'xref' => 'X1']));
    $body = json_decode((string) $response->getBody(), true);
    check($response->getStatusCode() === 200, 'Cancelling a pending change succeeds: ' . $response->getBody());
    check($body['cancelled'] === 2 && $body['xref'] === 'X1' && $body['tree'] === 'test', 'Receipt reports the cancelled changes');
    check(DB::table('change')->where('status', 'pending')->count() === 0, 'Both pending changes are rejected');
    check($body['records'][0]['xref'] === 'X1' && $body['records'][0]['state'] === 'deleted', 'Receipt describes the record state after cancellation');
    check($body['records'][0]['hash'] === RecordVersion::fromGedcom('')['hash'], 'Receipt hash is the empty GEDCOM it now has');

    // An approved record keeps its content in the receipt.
    $approved = "0 @X2@ SOUR\n1 TITL Approved";
    DB::table('sources')->insert(['s_file' => 1, 's_id' => 'X2', 's_gedcom' => $approved]);
    DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'X2', 'user_id' => 1, 'status' => 'pending', 'old_gedcom' => $approved, 'new_gedcom' => $approved . "\n1 AUTH Test"]);
    Registry::$records['X2'] = new GedcomRecord('X2', $approved, null, $tree);
    $response = $cancel->handle((new ServerRequest('DELETE', ''))->withQueryParams(['tree' => 'test', 'xref' => 'X2']));
    $body = json_decode((string) $response->getBody(), true);
    check($response->getStatusCode() === 200 && $body['cancelled'] === 1, 'Cancelling one pending change of an approved record succeeds');
    check($body['records'][0]['state'] === 'applied' && $body['records'][0]['hash'] === RecordVersion::fromGedcom($approved)['hash'], 'Receipt reports the approved content');

    // Nothing pending: still a 404, and no write.
    $response = $cancel->handle((new ServerRequest('DELETE', ''))->withQueryParams(['tree' => 'test', 'xref' => 'X2']));
    check($response->getStatusCode() === 404, 'No pending changes stays a 404');

    // A failing handler keeps its explanation in the MCP error instead of a
    // bare "Internal Server Error".
    $failure = new Response(500, [], 'Call to undefined method Fisharebest\Webtrees\Cache::clear()');
    try {
        McpProtocol::toolResult(1, $failure);
        throw new RuntimeException('Expected the tool result to raise the handler error');
    } catch (Exception $e) {
        check(str_contains($e->getMessage(), 'Cache::clear()'), 'The 500 body reaches the MCP client');
        check(str_starts_with($e->getMessage(), 'Internal Server Error: '), 'The status is not repeated in the exception message');
    }
    $explained = new Response(500, [], 'No pending changes found');
    try {
        McpProtocol::toolResult(1, $explained);
        throw new RuntimeException('Expected the tool result to raise the handler error');
    } catch (Exception $e) {
        check(str_contains($e->getMessage(), 'No pending changes found') && !str_contains($e->getMessage(), '{'), 'Plain-text bodies are passed through unchanged');
    }

    echo "PASS: {$checks} cancel-pending and MCP error-propagation checks.\n";
}
