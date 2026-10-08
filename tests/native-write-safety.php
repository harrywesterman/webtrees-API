<?php

/** Real webtrees Tree/GedcomRecord classes and API handlers; isolated identity/cache services, SQLite. */
declare(strict_types=1);

namespace Fisharebest\Webtrees {
    class Auth {
        public const PRIV_PRIVATE = 2, PRIV_USER = 1, PRIV_NONE = 0, PRIV_HIDE = -1;
        public static bool $editor = true, $moderator = false;
        public static string $autoAccept = '0';
        public static function id() { return 1; }
        public static function isEditor($tree) { return self::$editor; }
        public static function isModerator($tree) { return self::$moderator; }
        public static function user() { return new class {
            public function userName() { return 'editor'; }
            public function getPreference($key) { return Auth::$autoAccept; }
        }; }
        public static function checkRecordAccess($record, $edit = false) { return $record; }
    }
    class Log { public static function addEditLog($message, $tree) {} }
    class Registry {
        public static array $records = [];
        public static $response;
        public static int $next = 100;
        public static function responseFactory() { return self::$response; }
        public static function container() { return new class { public function get($class) { return new \stdClass(); } }; }
        public static function xrefFactory() { return new class { public function make($type) { return 'X' . ++Registry::$next; } }; }
        public static function individualFactory() { return new class {
            public function new($xref, $gedcom, $pending, $tree) { return Registry::$records[$xref] = new Individual($xref, $gedcom, $pending, $tree); }
        }; }
        public static function gedcomRecordFactory() { return new class {
            public function make($xref, $tree) { return Registry::$records[$xref] ?? null; }
            public function new($xref, $gedcom, $pending, $tree) { return Registry::$records[$xref] = new GedcomRecord($xref, $gedcom, $pending, $tree); }
        }; }
    }
}
namespace Fisharebest\Webtrees\Services {
    class TreeService {
        public function __construct(private $tree) {}
        public function all() { return new \Illuminate\Support\Collection(['test' => $this->tree]); }
    }
}
namespace Jefferson49\Webtrees\Helpers {
    class Functions {
        public static function getRecordFacts(...$args) { return new \Illuminate\Support\Collection(); }
        public static function getFromContainer($class) { return new \stdClass(); }
    }
    class Authorization { public static function accessLevelForTree($tree) { return 1; } }
}
namespace Jefferson49\Webtrees\Log {
    class CustomModuleLog { public static function addDebugLog(...$args) {} }
}
namespace {
    $root = getenv('WEBTREES_TEST_ROOT');
    if (!$root) throw new RuntimeException('Set WEBTREES_TEST_ROOT to an unpacked webtrees release.');
    require $root . '/vendor/autoload.php';
    require __DIR__ . '/../autoload.php';
    $common = new Composer\Autoload\ClassLoader();
    foreach (['Authorization', 'Module', 'Exceptions', 'Log'] as $ns) {
        $common->addPsr4('Jefferson49\\Webtrees\\' . $ns . '\\', __DIR__ . '/../vendor/jefferson49/webtrees-common/' . $ns);
    }
    $common->register(true);

    use Fisharebest\Webtrees\Auth;
    use Fisharebest\Webtrees\DB;
    use Fisharebest\Webtrees\GedcomRecord;
    use Fisharebest\Webtrees\Registry;
    use Fisharebest\Webtrees\Tree;
    use Fisharebest\Webtrees\Services\TreeService;
    use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordSnapshot;
    use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\AddFamily;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\AddSourceCitation;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CreateMediaUpload;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CreateRecords;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\Media;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\ModifyRecord;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\ModifySource;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\Middleware\McpProtocol;
    use Nyholm\Psr7\ServerRequest;

    $factory = new Nyholm\Psr7\Factory\Psr17Factory();
    Registry::$response = new Fisharebest\Webtrees\Factories\ResponseFactory($factory, $factory);
    (new ReflectionClass(McpProtocol::class))->getProperty('stream_factory')->setValue(null, $factory);
    $db = new DB();
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:']);
    $db->setAsGlobal();
    DB::schema()->create('gedcom', function ($t) { $t->integer('gedcom_id'); });
    DB::table('gedcom')->insert(['gedcom_id'=>1]);
    foreach (['individuals'=>'i', 'families'=>'f', 'sources'=>'s', 'media'=>'m', 'other'=>'o'] as $table=>$p) {
        DB::schema()->create($table, function ($t) use ($p) { $t->integer($p . '_file'); $t->string($p . '_id'); $t->text($p . '_gedcom'); });
    }
    DB::schema()->create('change', function ($t) {
        $t->increments('change_id'); $t->integer('gedcom_id'); $t->integer('user_id');
        $t->string('xref'); $t->string('status'); $t->text('old_gedcom'); $t->text('new_gedcom');
        $t->string('change_time')->default('2026-10-08 00:00:00');
    });
    DB::schema()->create('user', function ($t) { $t->integer('user_id'); $t->string('user_name'); $t->string('real_name'); });
    $tree = new Tree(1, 'test', 'Test', '', '', true, false, null, null);
    $trees = new TreeService($tree);
    $checks = 0;
    function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
    function request(array $input) { return (new ServerRequest('GET', ''))->withQueryParams(['tree'=>'test'] + $input); }

    // Run the handlers against SQLite, but also compile their actual read
    // builders with MySQL's grammar to verify current reads under REPEATABLE READ.
    $grammar = new class(DB::connection()) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public array $mysqlReads = [];
        public function compileSelect(Illuminate\Database\Query\Builder $query) {
            $this->mysqlReads[] = (new Illuminate\Database\Query\Grammars\MySqlGrammar($query->getConnection()))->compileSelect(clone $query);
            return parent::compileSelect($query);
        }
    };
    DB::connection()->setQueryGrammar($grammar);

    $create = new CreateRecords($trees);
    $input = ['idempotency-key'=>'native-batch-001', 'records'=>[
        ['id'=>'person', 'record-type'=>'INDI', 'gedcom'=>"1 NAME Test\n1 FAMS @family@"],
        ['id'=>'family', 'record-type'=>'FAM', 'gedcom'=>'1 HUSB @person@'],
    ]];
    $response = $create->handle(request($input));
    $receipt = json_decode((string) $response->getBody(), true);
    check($response->getStatusCode() === 202 && DB::table('change')->count() === 2, 'One complete creation row per native batch record');
    foreach ($receipt['records'] as $item) {
        $row = DB::table('change')->where('xref', $item['xref'])->first();
        check($row->old_gedcom === '' && str_contains($row->new_gedcom, '1 CHAN') && str_contains($row->new_gedcom, '2 _WT_USER editor'), 'Native batch retains creation audit data');
        check($item['hash'] === RecordVersion::fromGedcom($row->new_gedcom)['hash'], 'Native batch hash matches complete creation GEDCOM');
    }
    check($create->handle(request($input))->getStatusCode() === 200 && DB::table('change')->count() === 2, 'Batch replay does not insert skeletons or duplicates');
    $historyReads = array_values(array_filter($grammar->mysqlReads, fn ($sql) => str_contains($sql, '`new_gedcom` like')));
    check(count($historyReads) === 2 && count(array_filter($historyReads, fn ($sql) => str_ends_with($sql, 'for update'))) === 2, 'First batch and replay both read current MySQL history, including commits after an earlier snapshot');
    // Approved replay must still work with an empty factory cache (including a
    // transaction whose earlier consistent reads could not see the new xrefs).
    foreach ($receipt['records'] as $item) {
        $row = DB::table('change')->where('xref', $item['xref'])->first();
        $family = str_starts_with($row->new_gedcom, '0 @' . $item['xref'] . "@ FAM\n");
        $prefix = $family ? 'f' : 'i';
        DB::table($family ? 'families' : 'individuals')->insert([$prefix . '_file'=>1, $prefix . '_id'=>$item['xref'], $prefix . '_gedcom'=>$row->new_gedcom]);
    }
    DB::table('change')->update(['status'=>'accepted']);
    Registry::$records = [];
    $grammar->mysqlReads = [];
    $approvedReplay = json_decode((string) $create->handle(request($input))->getBody(), true);
    check(array_column($approvedReplay['records'], 'hash') === array_column($receipt['records'], 'hash'), 'Approved replay returns the actual creation hashes without cached records');
    $versionReads = array_filter($grammar->mysqlReads, fn ($sql) => str_starts_with($sql, 'select * from `change`') || str_contains($sql, '_gedcom` from'));
    check(count($versionReads) >= 5 && count(array_filter($versionReads, fn ($sql) => !str_ends_with($sql, 'for update'))) === 0, 'Replay versions use current pending and approved reads under the same lock');
    DB::table('change')->delete();

    $old = "0 @I1@ INDI\n1 NAME Test";
    Registry::$records['I1'] = new GedcomRecord('I1', $old, null, $tree);
    $accepted = $old . "\n1 OCCU Farmer";
    DB::table('individuals')->insert(['i_id'=>'I1', 'i_file'=>1, 'i_gedcom'=>$accepted]);
    $modify = new ModifyRecord($trees);
    $merge = request(['xref'=>'I1', 'gedcom'=>'1 NOTE Added', 'mode'=>'merge']);
    check($modify->handle($merge)->getStatusCode() === 409 && DB::table('change')->count() === 0, 'Native merge rejects a cached approved snapshot');
    Registry::$records['I1'] = new GedcomRecord('I1', $accepted, null, $tree);
    check($modify->handle($merge)->getStatusCode() === 202, 'Native merge accepts a reloaded approved snapshot');
    $pending = DB::table('change')->orderByDesc('change_id')->first();
    check(str_contains($pending->new_gedcom, '1 OCCU Farmer') && str_contains($pending->new_gedcom, '1 NOTE Added'), 'Native merge preserves concurrently approved facts');
    check(DB::connection()->transaction(fn () => RecordSnapshot::isCurrent(Registry::$records['I1'])), 'Native latest pending snapshot remains editable');
    DB::table('change')->where('xref','I1')->update(['status'=>'accepted']);
    check(!DB::connection()->transaction(fn () => RecordSnapshot::isCurrent(Registry::$records['I1'])), 'Native stale pending object is rejected after moderation');
    DB::table('change')->delete();

    $media = new Media($trees, new Fisharebest\Webtrees\Services\LinkedRecordService());
    Registry::$records['I1'] = new GedcomRecord('I1', $old . "\n1 NOTE Keep", null, $tree);
    $noop = request(['xref'=>'I1', 'gedcom'=>'1 NOTE Keep', 'mode'=>'merge']);
    check($modify->handle($noop)->getStatusCode() === 409 && DB::table('change')->count() === 0, 'No-op against a cached fact removed from the approved record returns a conflict');
    Registry::$records['I1'] = new GedcomRecord('I1', $accepted, null, $tree);
    $currentNoop = $modify->handle(request(['xref'=>'I1', 'gedcom'=>'1 OCCU Farmer', 'mode'=>'merge']));
    check($currentNoop->getStatusCode() === 200 && json_decode((string) $currentNoop->getBody(), true)['changed'] === false && DB::table('change')->count() === 0, 'Current no-op remains successful without a write');

    DB::table('user')->insert(['user_id'=>1, 'user_name'=>'editor', 'real_name'=>'Test Editor']);
    $conflictId = DB::table('change')->insertGetId(['gedcom_id'=>1, 'xref'=>'I1', 'user_id'=>1, 'status'=>'pending', 'old_gedcom'=>$accepted, 'new_gedcom'=>$accepted . "\n1 NOTE Concurrent"]);
    $grammar->mysqlReads = [];
    $snapshot = DB::connection()->transaction(fn () => RecordSnapshot::read(Registry::$records['I1']));
    check(!$snapshot['current'] && (int) $snapshot['pending'][0]->change_id === $conflictId && $snapshot['pending'][0]->real_name === 'Test Editor', 'Guard returns the exact conflicting change rows with author metadata');
    $pendingReads = array_values(array_filter($grammar->mysqlReads, fn ($sql) => str_starts_with($sql, 'select * from `change`')));
    check(count($pendingReads) === 1 && str_ends_with($pendingReads[0], 'for update') && !str_contains($pendingReads[0], 'join'), 'Pending guard uses a current locking read without a nullable join');
    $mutation = new ReflectionMethod(Media::class, 'mutate');
    $grammar->mysqlReads = [];
    try {
        $mutation->invoke($media, [Registry::$records['I1']], function () { throw new RuntimeException('Stale media write must never run'); });
        throw new RuntimeException('Expected media conflict');
    } catch (DomainException $e) {
        check($e->getCode() === 409 && str_contains($e->getMessage(), 'change-id=' . $conflictId), 'Media conflict identifies the change detected by the locking guard');
    }
    $pendingReads = array_values(array_filter($grammar->mysqlReads, fn ($sql) => str_starts_with($sql, 'select * from `change`')));
    check(count($pendingReads) === 1 && str_ends_with($pendingReads[0], 'for update'), 'Media conflict reuses guard rows instead of rereading an older snapshot');
    DB::table('change')->delete();
    $stage = new CreateMediaUpload($media, $trees);
    $upload = request(['target-xref'=>'I1', 'target-type'=>'INDI', 'filename'=>'test.png'])
        ->withAttribute('oauth_scopes', ['mcp_write'])->withAttribute('oauth_user_id',1);
    foreach (['moderator', 'auto-accept', 'non-editor'] as $denial) {
        Auth::$moderator = $denial === 'moderator';
        Auth::$autoAccept = $denial === 'auto-accept' ? '1' : '0';
        Auth::$editor = $denial !== 'non-editor';
        check($stage->handle($upload)->getStatusCode() === 403, 'Production write policy denies staging for ' . $denial);
        try {
            $media->commitUpload($tree, $upload->getQueryParams(), 'bytes', 'test.png', 20);
            throw new RuntimeException('Expected PUT denial');
        } catch (DomainException $e) { check($e->getCode() === 403, 'Production write policy denies PUT for ' . $denial); }
    }
    Auth::$moderator = false; Auth::$autoAccept = '0'; Auth::$editor = true;

    // Native Tree::createRecord returns GedcomRecord, whereas createIndividual
    // returns Individual. Exercise the actual typed link method, not a double.
    $familyResponse = (new AddFamily($trees))->handle(request(['idempotency-key'=>'native-family-001', 'husband'=>['name'=>'New person']]));
    check($familyResponse->getStatusCode() === 202, 'Native add-family accepts a newly created Individual: ' . $familyResponse->getBody());
    check(count(array_filter(Registry::$records, fn ($record) => $record instanceof Fisharebest\Webtrees\Individual)) === 1, 'Native family participant has the correct record class');
    DB::table('change')->delete();

    $duplicate = "0 @S1@ SOUR\n1 TITL First\n1 TITL Second";
    Registry::$records['S1'] = new GedcomRecord('S1', $duplicate, null, $tree);
    $sources = new ModifySource($trees);
    foreach (['title'=>'New', 'author'=>[], 'note'=>"Bad\0note"] as $field=>$value) {
        $response = $sources->handle(request(['xref'=>'S1', $field=>$value]));
        $expected = $field === 'title' ? 409 : 400;
        check($response->getStatusCode() === $expected && DB::table('change')->count() === 0, 'Source domain error retains HTTP status and performs no write');
        $rpc = json_decode((string) McpProtocol::toolResult(1, $response), true);
        check($rpc['result']['isError'] && !isset($rpc['error']) && str_contains($rpc['result']['content'][0]['text'], (string) $expected), 'Source domain error is an MCP tool error, not -32603');
    }
    Registry::$records['I1'] = new GedcomRecord('I1', $old, null, $tree);
    $citations = new AddSourceCitation($trees);
    $missingEvent = $citations->handle(request(['target-xref'=>'I1', 'source-xref'=>'S1', 'event'=>'BIRT']));
    check($missingEvent->getStatusCode() === 404 && str_contains((string) $missingEvent->getBody(), 'Event not found'), 'Missing citation event retains 404 and explanation');
    $batchError = $citations->handle(request(['target-xref'=>'I1', 'citations'=>[
        ['source-xref'=>'S1'], ['source-xref'=>'S1', 'event'=>'BIRT'],
    ]]));
    check($batchError->getStatusCode() === 404 && DB::table('change')->count() === 0, 'Later invalid citation prevents the entire citation batch write');
    check(Registry::$records['S1']->gedcom() === $duplicate, 'Ambiguous source content is preserved');
    echo "PASS: $checks native record/write-safety and source-error checks.\n";
}
