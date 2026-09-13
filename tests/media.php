<?php
/** Isolated tests: real SQLite transactions/PSR-7/Flysystem, record/access doubles. */
declare(strict_types=1);
namespace {
    $root = getenv('WEBTREES_TEST_ROOT');
    if (!$root) { exit("Set WEBTREES_TEST_ROOT to an unpacked webtrees 2.2 release.\n"); }
    require $root . '/vendor/autoload.php';
    require __DIR__ . '/../vendor/autoload.php';
}
namespace Fisharebest\Webtrees {
    class DB extends \Illuminate\Database\Capsule\Manager {}
    class Registry {
        public static array $records = [];
        public static function gedcomRecordFactory() { return new class { public function make($id, $tree) { return Registry::$records[$id] ?? null; } }; }
    }
    class Tree {
        public function __construct(public $fs) {}
        public function id() { return 1; }
        public function mediaFilesystem() { return $this->fs; }
        public function createRecord($gedcom) {
            $id = 'M' . (count(Registry::$records) + 10);
            $record = new Media($id, str_replace('@@', '@' . $id . '@', $gedcom), $this);
            Registry::$records[$id] = $record;
            $record->updateRecord($record->data, true);
            return $record;
        }
    }
    class GedcomRecord {
        public bool $pending = false;
        public bool $private = false;
        public bool $fail = false;
        public bool $hiddenFacts = false;
        public function __construct(private string $id, public string $data, private Tree $tree) {}
        public function xref() { return $this->id; }
        public function tree() { return $this->tree; }
        public function gedcom() { return $this->data; }
        public function tag() { return explode(' ', explode("\n", $this->data)[0])[2]; }
        public function isPendingAddition() { return $this->pending; }
        public function isPendingDeletion() { return false; }
        public function url() { return 'https://example.test/media/' . $this->id; }
        public function updateRecord($gedcom, $chan) {
            if ($this->fail) { throw new \RuntimeException('Injected failure'); }
            DB::table('change')->insert(['gedcom_id' => 1, 'xref' => $this->id, 'status' => 'pending', 'new_gedcom' => $gedcom]);
        }
        public function deleteRecord() { $this->updateRecord('', false); }
    }
    class Media extends GedcomRecord {}
    class MediaFile {
        public function __construct(private string $data, $record) {}
        public function filename() { return substr(explode("\n", $this->data)[0], 7); }
        public function title() { return ''; }
    }
}
namespace Fisharebest\Webtrees\Services {
    class TreeService {
        public function __construct(private $tree) {}
        public function all() { return ['test' => $this->tree]; }
    }
    class LinkedRecordService {
        public array $linked = [];
        public function allLinkedRecords($record) { return new \Illuminate\Support\Collection($this->linked); }
    }
}
namespace Jefferson49\Webtrees\Authorization {
    class Auth { public const PRIV_HIDE = 3; public const PRIV_PRIVATE = 0; }
}
namespace Jefferson49\Webtrees\Helpers {
    class Authorization { public static function accessLevelForTree($tree) { return 1; } }
    class Functions {
        public static function getRecordFacts($record, $tags, $sort, $level, $pending) {
            if ($tags === []) { return new \Illuminate\Support\Collection($record->hiddenFacts && $level === 3 ? [1] : []); }
            preg_match_all('/(?:^|\n)(1 (?:FILE|NOTE|_DATE)[^\n]*(?:\n[2-9] [^\n]*)*)/', $record->data, $matches);
            $facts = [];
            foreach ($matches[1] as $data) {
                $tag = explode(' ', $data)[1];
                if (!in_array($tag, $tags) || ($level === 0 && str_contains($data, 'private.png'))) { continue; }
                $facts[] = new class($data, $tag) {
                    public function __construct(private $data, private $name) {}
                    public function gedcom() { return $this->data; }
                    public function tag() { return $this->name; }
                    public function value() { return substr($this->data, strlen($this->name) + 3); }
                };
            }
            return new \Illuminate\Support\Collection($facts);
        }
    }
}
namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation {
    class CheckAccess {
        public static bool $write = true;
        public static bool $privacy = true;
        public static function checkRecordAccess($record, $edit = false, $privacy = false) { return new \Nyholm\Psr7\Response($record->private ? 403 : 200); }
        public static function checkUserWriteAccess($tree) { return new \Nyholm\Psr7\Response(self::$write ? 200 : 403); }
        public static function checkTreePrivacy($tree) { return new \Nyholm\Psr7\Response(self::$privacy ? 200 : 403); }
    }
}
namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers {
    function api_response($content = '', $code = 200, $headers = []) {
        return new \Nyholm\Psr7\Response($code, $headers, is_string($content) ? $content : json_encode($content));
    }
}
namespace {
    use Fisharebest\Webtrees\DB;
    use Fisharebest\Webtrees\Registry;
    use Fisharebest\Webtrees\GedcomRecord;
    use Fisharebest\Webtrees\Media as Record;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\Media;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
    use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
    use Nyholm\Psr7\ServerRequest;
    use Nyholm\Psr7\UploadedFile;
    spl_autoload_register(function ($class) {
        $prefix = 'Jefferson49\\Webtrees\\Module\\WebtreesApi\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    });
    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->setAsGlobal();
    DB::schema()->create('gedcom', function ($t) { $t->integer('gedcom_id'); });
    DB::table('gedcom')->insert(['gedcom_id' => 1]);
    DB::schema()->create('change', function ($t) { $t->integer('gedcom_id'); $t->string('xref'); $t->string('status'); $t->text('new_gedcom'); });
    $dir = sys_get_temp_dir() . '/media-behavior-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $fs = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($dir));
    $tree = new \Fisharebest\Webtrees\Tree($fs);
    $links = new \Fisharebest\Webtrees\Services\LinkedRecordService();
    $handler = new Media(new \Fisharebest\Webtrees\Services\TreeService($tree), $links);
    $checks = 0;
    function check($ok, $message) { global $checks; ++$checks; if (!$ok) { throw new \RuntimeException($message); } }
    function rejects($fn, $code) { try { $fn(); } catch (\DomainException $e) { check($e->getCode() === $code, $e->getMessage()); return; } throw new \RuntimeException('Expected rejection'); }
    function request($method, $input, $scope = 'api_write') {
        $r = (new ServerRequest($method, 'https://example.test/api/media'))->withAttribute('oauth_scopes', [$scope]);
        return $method === 'GET' ? $r->withQueryParams($input) : $r->withParsedBody($input);
    }
    function approved() { DB::table('change')->delete(); }
    $image = imagecreatetruecolor(2, 2);
    ob_start(); imagepng($image); $png = ob_get_clean();
    ob_start(); imagejpeg($image); $jpg = ob_get_clean();
    check(MediaInput::image($png, 'photo.png', 10000) === 'image/png', 'PNG');
    check(MediaInput::image($jpg, 'photo.jpg', 10000) === 'image/jpeg', 'JPEG');
    rejects(fn () => MediaInput::image($png, 'photo.jpg', 10000), 415);
    rejects(fn () => MediaInput::image('%PDF-1.4', 'scan.pdf', 10000), 415);
    rejects(fn () => MediaInput::image($png, 'photo.png', 1), 413);
    foreach (['../photo.png', '/photo.png', 'C:\\photo.png', '.hidden.png', "a\0.png"] as $name) { rejects(fn () => MediaInput::filename($name), 400); }
    foreach (['../secret', '/secret', 'https://example.test/a', 'a/./b', 'a//b'] as $path) { rejects(fn () => MediaInput::path($path), 400); }
    check(MediaInput::base64(base64_encode($png)) === $png, 'base64');
    rejects(fn () => MediaInput::base64('%%%'), 400);
    rejects(fn () => MediaInput::base64(str_repeat('A', 6990510)), 413);
    rejects(fn () => MediaInput::base64("YQ==\n"), 400);
    check(str_contains(MediaInput::field(1, 'NOTE', "text\n1 FILE evil"), '2 CONT 1 FILE evil'), 'GEDCOM injection');
    $paths = [];
    foreach (['INDI', 'FAM', 'SOUR'] as $type) {
        approved(); Registry::$records = ['I1' => new GedcomRecord('I1', '0 @I1@ ' . $type, $tree)];
        $input = ['tree' => 'test', 'target-xref' => 'I1', 'target-type' => $type, 'title' => 'Photo'];
        $response = $handler->handle(request('POST', $input)->withUploadedFiles(['file' => new UploadedFile(\Nyholm\Psr7\Stream::create($png), strlen($png), UPLOAD_ERR_OK, 'photo.png', 'text/plain')]));
        check($response->getStatusCode() === 201, 'Multipart ' . $type . ': ' . $response->getBody());
        $result = json_decode((string) $response->getBody(), true);
        $paths[] = $result['filename'];
        check($fs->read($result['filename']) === $png, 'Stored bytes');
        check(DB::table('change')->count() === 2, 'Both records pending');
        $data = DB::table('change')->where('xref', $result['xref'])->value('new_gedcom');
        check(str_contains($data, " OBJE\n1 FILE api-media/") && str_contains($data, "\n2 TITL Photo"), 'GEDCOM');
    }
    check(count(array_unique($paths)) === 3, 'Duplicate basenames do not overwrite');
    approved(); Registry::$records = ['I1' => new GedcomRecord('I1', '0 @I1@ INDI', $tree)];
    $input = ['tree' => 'test', 'target-xref' => 'I1', 'target-type' => 'INDI', 'filename' => 'photo.png', 'content-base64' => base64_encode($png)];
    $mcp = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_write'])->withAttribute('media_mcp', true)->withQueryParams($input);
    check($handler->execute($mcp, 'upload-media')->getStatusCode() === 201, 'MCP upload');
    check($handler->execute($mcp->withAttribute('oauth_scopes', ['mcp_read_member']), 'upload-media')->getStatusCode() === 403, 'Read cannot upload');
    check($handler->execute($mcp->withQueryParams(array_replace($input, ['tree' => 'absent'])), 'upload-media')->getStatusCode() === 404, 'Missing tree');
    check($handler->execute($mcp->withQueryParams(array_replace($input, ['target-xref' => 'I999'])), 'upload-media')->getStatusCode() === 404, 'Missing target');
    check($handler->execute($mcp->withQueryParams(array_replace($input, ['target-type' => 'FAM'])), 'upload-media')->getStatusCode() === 400, 'Wrong target type');
    check($handler->handle(request('POST', ['tree' => 'test', 'target-xref' => 'I1', 'target-type' => 'INDI']))->getStatusCode() === 400, 'Missing multipart file');
    approved(); Registry::$records['I1']->fail = true;
    $before = count($fs->listContents('', true)->filter(fn ($i) => $i->isFile())->toArray());
    check($handler->execute($mcp, 'upload-media')->getStatusCode() === 500, 'Injected failure');
    check(DB::table('change')->count() === 0, 'DB rollback');
    check($before === count($fs->listContents('', true)->filter(fn ($i) => $i->isFile())->toArray()), 'File rollback');
    Registry::$records['I1']->fail = false;
    $record = new Record('M1', "0 @M1@ OBJE\n1 FILE photo.png\n2 FORM PNG\n2 TITL Original\n1 NOTE Keep\n1 _DATE 1900\n1 RESN none", $tree);
    Registry::$records['M1'] = $record;
    $fs->write('photo.png', $png);
    check($handler->handle(request('PUT', ['tree' => 'test', 'xref' => 'M1', 'title' => 'Changed']))->getStatusCode() === 202, 'Update');
    $updated = DB::table('change')->value('new_gedcom');
    check(str_contains($updated, "\n2 TITL Changed\n1 NOTE Keep") && str_contains($updated, '1 _DATE 1900') && str_contains($updated, '1 RESN none'), 'Preserve metadata');
    check($handler->handle(request('PUT', ['tree' => 'test', 'xref' => 'M1', 'title' => 'Again']))->getStatusCode() === 409, 'Pending conflict');
    approved(); CheckAccess::$write = false;
    check($handler->handle(request('DELETE', ['tree' => 'test', 'xref' => 'M1']))->getStatusCode() === 403, 'Write access');
    CheckAccess::$write = true; $record->private = true;
    check($handler->handle(request('GET', ['tree' => 'test', 'xref' => 'M1'], 'api_read_member'))->getStatusCode() === 403, 'Record privacy');
    $record->private = false; $record->hiddenFacts = true;
    check($handler->handle(request('PUT', ['tree' => 'test', 'xref' => 'M1', 'note' => 'x']))->getStatusCode() === 403, 'Private facts');
    $record->hiddenFacts = false;
    $json = (new ServerRequest('PUT', '', ['Content-Type' => 'application/json'], json_encode(['tree' => 'test', 'xref' => 'M1', 'note' => 'JSON note'])))
        ->withAttribute('oauth_scopes', ['api_write']);
    check($handler->handle($json)->getStatusCode() === 202, 'JSON request body');
    approved();
    check($handler->handle($json->withQueryParams(['tree' => 'other']))->getStatusCode() === 400, 'Conflicting query/body rejected');
    $record->pending = true;
    check($handler->handle(request('PUT', ['tree' => 'test', 'xref' => 'M1', 'title' => 'x']))->getStatusCode() === 409, 'Pending record rejected');
    $record->pending = false;
    $read = request('GET', ['tree' => 'test', 'xref' => 'M1', 'filename' => 'photo.png'], 'api_read_privacy');
    $response = $handler->execute($read, 'download-media');
    check($response->getStatusCode() === 200 && (string) $response->getBody() === $png, 'Download');
    $record->data .= "\n1 FILE private.png";
    check(!str_contains((string) $handler->handle($read)->getBody(), 'private.png'), 'Fact privacy');
    $missing = request('GET', ['tree' => 'test', 'xref' => 'M1', 'filename' => 'private.png'], 'api_read_member');
    check($handler->execute($missing, 'download-media')->getStatusCode() === 404, 'Missing stored file');
    CheckAccess::$privacy = false;
    check($handler->execute($read, 'download-media')->getStatusCode() === 403, 'Tree privacy');
    CheckAccess::$privacy = true;
    $linkInput = ['tree' => 'test', 'xref' => 'M1', 'target-xref' => 'I1', 'target-type' => 'INDI'];
    check($handler->execute(request('POST', $linkInput), 'link-media')->getStatusCode() === 202, 'Link');
    Registry::$records['I1']->data = DB::table('change')->where('xref', 'I1')->value('new_gedcom');
    approved();
    check($handler->execute(request('POST', $linkInput), 'link-media')->getStatusCode() === 200, 'Duplicate link');
    check($handler->execute(request('DELETE', $linkInput), 'unlink-media')->getStatusCode() === 202, 'Unlink');
    approved(); $links->linked = [Registry::$records['I1']];
    check($handler->handle(request('DELETE', ['tree' => 'test', 'xref' => 'M1']))->getStatusCode() === 409, 'Linked deletion');
    $links->linked = [];
    DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'I9', 'status' => 'pending', 'new_gedcom' => "0 @I9@ INDI\n1 OBJE @M1@"]);
    check($handler->handle(request('DELETE', ['tree' => 'test', 'xref' => 'M1']))->getStatusCode() === 409, 'Pending link prevents deletion');
    approved();
    check($handler->handle(request('DELETE', ['tree' => 'test', 'xref' => 'M1']))->getStatusCode() === 202, 'Delete pending');
    check($fs->fileExists('photo.png'), 'Shared file retained');
    check($handler->handle(request('PATCH', []))->getStatusCode() === 405, 'Methods');
    foreach (MediaTools::ACTIONS as $action) {
        $tool = MediaTools::description($action);
        check($tool['name'] === $action && strlen($tool['description']) > 100, 'Tool description');
    }
    check(isset(MediaTools::openApiPaths()['/media']['post']['requestBody']['content']['multipart/form-data']), 'Swagger multipart');
    foreach ($fs->listContents('', true)->filter(fn ($i) => $i->isFile()) as $item) { $fs->delete($item->path()); }
    $fs->deleteDirectory('api-media'); rmdir($dir);
    echo "PASS: $checks checks (record/access doubles; real SQLite, PSR-7, Flysystem).\n";
}
