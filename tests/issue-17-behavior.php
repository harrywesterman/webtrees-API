<?php
/** Included by media.php: production handlers, real SQLite/Flysystem/PSR-7. */
declare(strict_types=1);

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\GedcomRecord;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordBatch;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\GedcomRecordMutation;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CreateMediaUpload;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\UploadMediaBatch;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CreateRecords;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\CancelledXrefs;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\VerifyWrite;
use Nyholm\Psr7\ServerRequest;

approved();
Registry::$records = ['I1' => new GedcomRecord('I1', "0 @I1@ INDI\n1 NAME Test\n1 OCCU Carpenter", $tree)];
$base = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_write'])->withAttribute('media_mcp', true)
    ->withQueryParams(['tree' => 'test', 'target-xref' => 'I1', 'target-type' => 'INDI', 'filename' => 'one.png', 'content-base64' => base64_encode($png)]);
$first = json_decode((string) $handler->execute($base, 'upload-media')->getBody(), true);
$secondResponse = $handler->execute($base->withQueryParams(array_replace($base->getQueryParams(), ['filename' => 'two.png'])), 'upload-media');
$second = json_decode((string) $secondResponse->getBody(), true);
check($secondResponse->getStatusCode() === 201, 'Second media upload queues on same pending target');
$latest = DB::table('change')->where('xref', 'I1')->orderByDesc('change_id')->value('new_gedcom');
check(str_contains($latest, '@' . $first['xref'] . '@') && str_contains($latest, '@' . $second['xref'] . '@') && str_contains($latest, '1 OCCU Carpenter'), 'Queued target preserves first media and original facts');
check(str_contains(Registry::$records[$second['xref']]->gedcom(), '1 CHAN'), 'Receipt fixture includes server-generated CHAN');
check($second['hash'] === RecordVersion::fromGedcom(Registry::$records[$second['xref']]->gedcom())['hash'], 'Upload hash is for response xref');
$targetReceipt = array_values(array_filter($second['records'], fn ($v) => $v['xref'] === 'I1'))[0];
check($targetReceipt['hash'] === RecordVersion::fromGedcom($latest)['hash'], 'Upload returns latest target hash too');
// Simulate another writer after this request loaded its target snapshot.
DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'I1', 'status' => 'pending', 'new_gedcom' => $latest . "\n1 NOTE Concurrent"]);
$blocking = (string) DB::table('change')->max('change_id');
$beforeCount = DB::table('change')->count();
$stale = $handler->execute($base, 'upload-media');
check($stale->getStatusCode() === 409 && str_contains((string) $stale->getBody(), 'change-id=') && str_contains((string) $stale->getBody(), $blocking) && str_contains((string) $stale->getBody(), 'xref=I1'), 'Stale queue conflict identifies blocking changes');
check(DB::table('change')->count() === $beforeCount, 'Stale queue attempt rolls back all writes');
$captured = Registry::$records['I1']->gedcom();
$receipt = RecordVersion::receipt($tree, ['xref' => 'I1'], [Registry::$records['I1']]);
check($receipt['hash'] === RecordVersion::fromGedcom($captured)['hash'] && $receipt['hash'] !== RecordVersion::fromGedcom($latest . "\n1 NOTE Concurrent")['hash'], 'Receipt identifies the exact write snapshot, not a later concurrent edit');
DB::table('change')->where('xref', 'I1')->delete();
check($handler->execute($base, 'upload-media')->getStatusCode() === 409, 'Removed pending history cannot silently resurrect a cached pending snapshot');

$before = "0 @I1@ INDI\n1 NAME Old\n2 GIVN Full\n2 SURN Name\n1 OCCU Farmer\n2 SOUR @S1@\n1 NOTE Same\n1 NOTE Same";
$after = "0 @I1@ INDI\n1 NAME Old\n2 SURN Name\n1 NOTE Same";
$removed = GedcomRecordMutation::removedFacts($before, $after);
check(count($removed) === 3 && in_array("1 NAME Old\n2 GIVN Full\n2 SURN Name", $removed, true) && in_array("1 OCCU Farmer\n2 SOUR @S1@", $removed, true), 'Full diff detects lost name sub-tags, citation and duplicate fact');
$merged = GedcomRecordMutation::mergeFacts($before, "1 OCCU Carpenter\n2 DATE 1900\n1 NOTE Same");
check(GedcomRecordMutation::removedFacts($before, $merged) === [] && str_contains($merged, '1 OCCU Carpenter'), 'Merge adds facts without losing existing data');
check(GedcomRecordMutation::mergeFacts($merged, "1 OCCU Carpenter\n2 DATE 1900") === $merged, 'Merge replay does not duplicate exact blocks');

approved();
Registry::$records['I1'] = new GedcomRecord('I1', $before, $tree);
$modify = new \Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\ModifyRecord(new \Fisharebest\Webtrees\Services\TreeService($tree));
$modifyRequest = (new ServerRequest('GET', ''))->withQueryParams(['tree' => 'test', 'xref' => 'I1', 'gedcom' => "1 NAME Old\n2 SURN Name\n1 NOTE Same", 'dry-run' => true]);
$previewResponse = $modify->handle($modifyRequest);
$preview = json_decode((string) $previewResponse->getBody(), true);
check($previewResponse->getStatusCode() === 200 && count($preview['removed-facts']) === 3 && DB::table('change')->count() === 0, 'Actual modify-record dry-run reports every removed block without writing');
$mergeRequest = $modifyRequest->withQueryParams(['tree' => 'test', 'xref' => 'I1', 'gedcom' => '1 OCCU Carpenter', 'mode' => 'merge']);
$mergeResponse = $modify->handle($mergeRequest);
check($mergeResponse->getStatusCode() === 202 && str_contains(Registry::$records['I1']->gedcom(), '2 GIVN Full'), 'Actual merge preserves name sub-tags: ' . $mergeResponse->getBody());
$noteResponse = $modify->handle($mergeRequest->withQueryParams(array_replace($mergeRequest->getQueryParams(), ['gedcom' => '1 NOTE Another'])));
check($noteResponse->getStatusCode() === 202 && str_contains(Registry::$records['I1']->gedcom(), '1 OCCU Carpenter'), 'Incremental merge queues against latest pending snapshot');
$count = DB::table('change')->count();
check($modify->handle($mergeRequest)->getStatusCode() === 200 && DB::table('change')->count() === $count, 'Exact merge replay is a no-op');

approved();
Registry::$records = ['I1' => new GedcomRecord('I1', '0 @I1@ INDI', $tree), 'I2' => new GedcomRecord('I2', '0 @I2@ INDI', $tree)];
Registry::container(new class {
    public function set($class, $value) {}
    public function get($class) { return new class { public function getPreference($name, $default = '') { return 'test-encryption-key'; } }; }
});
$trees = new \Fisharebest\Webtrees\Services\TreeService($tree);
$staged = new CreateMediaUpload($handler, $trees);
$batch = new UploadMediaBatch($handler, $staged);
$stageRequest = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_write'])->withAttribute('oauth_user_id', 1)->withAttribute('base_url', 'https://example.test')
    ->withQueryParams(['tree' => 'test', 'files' => [
        ['filename' => 'one.png', 'target-xref' => 'I1', 'target-type' => 'INDI'],
        ['filename' => 'two.pdf', 'target-xref' => 'I2', 'target-type' => 'INDI', 'title' => 'Akte'],
    ]]);
$urlsResponse = $batch->handle($stageRequest);
$urls = json_decode((string) $urlsResponse->getBody(), true);
check($urlsResponse->getStatusCode() === 201 && count($urls['files']) === 2 && $urls['staged'] === true, 'Server MCP batch stages multiple targets without content-base64: ' . $urlsResponse->getBody());
check(DB::table('change')->count() === 0, 'Staging writes no records before bytes arrive');
foreach ($urls['files'] as $item) {
    parse_str(parse_url($item['upload-url'], PHP_URL_QUERY), $query);
    $claims = \Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken::verify($query['token'], \Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken::key('test-encryption-key'), 'upload');
    check($claims['xref'] === $item['target-xref'] && $claims['user-id'] === 1, 'Each URL binds its own target and authenticated user');
}
$bad = $stageRequest->withQueryParams(['tree' => 'test', 'files' => [['filename' => 'one.png', 'target-xref' => 'I1', 'target-type' => 'INDI'], ['filename' => '../bad.png', 'target-xref' => 'I2', 'target-type' => 'INDI']]]);
check($batch->handle($bad)->getStatusCode() === 400 && DB::table('change')->count() === 0, 'Invalid batch file creates no pending changes');
// Signed URLs must enforce the same write policy as inline uploads, both when
// staging and again on PUT (permissions may change during the URL lifetime).
\Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess::$write = false;
check($batch->handle($stageRequest)->getStatusCode() === 403, 'Staged batch denies disallowed API writers');
$singleStage = $stageRequest->withQueryParams(['tree'=>'test', 'filename'=>'one.png', 'target-xref'=>'I1', 'target-type'=>'INDI']);
$singleUpload = new \Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\UploadMedia($handler, $staged);
check($singleUpload->handle($singleStage)->getStatusCode() === 403, 'Default upload-media transport denies disallowed API writers');
parse_str(parse_url($urls['files'][0]['upload-url'], PHP_URL_QUERY), $tokenQuery);
$put = new \Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\MediaUpload($handler, $trees, $dir . '/review-upload-spool');
$deniedPut = $put->handle((new ServerRequest('PUT', '', [], $png))->withQueryParams($tokenQuery));
check($deniedPut->getStatusCode() === 403 && DB::table('change')->count() === 0, 'Previously staged PUT rechecks current write permission before any write: ' . $deniedPut->getStatusCode() . ' ' . $deniedPut->getBody());
foreach (glob($dir . '/review-upload-spool/*') ?: [] as $file) unlink($file);
rmdir($dir . '/review-upload-spool');
\Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess::$write = true;

// Cached object predates a concurrent accepted change. There are no pending
// rows left to detect, so compare with the approved GEDCOM under the write lock.
$old = "0 @I1@ INDI\n1 NAME Old";
$accepted = $old . "\n1 OCCU Farmer";
Registry::$records['I1'] = new GedcomRecord('I1', $old, $tree);
DB::table('individuals')->where('i_id', 'I1')->update(['i_gedcom'=>$accepted]);
DB::table('change')->insert(['gedcom_id'=>1, 'xref'=>'I1', 'status'=>'accepted', 'old_gedcom'=>$old, 'new_gedcom'=>$accepted]);
$beforeConcurrent = DB::table('change')->count();
$staleMerge = $modify->handle($mergeRequest->withQueryParams(['tree'=>'test', 'xref'=>'I1', 'gedcom'=>'1 NOTE Added', 'mode'=>'merge']));
check($staleMerge->getStatusCode() === 409 && DB::table('change')->count() === $beforeConcurrent, 'Merge rejects stale approved snapshot without removing concurrent facts');
$staleMedia = $handler->execute($base, 'upload-media');
check($staleMedia->getStatusCode() === 409 && DB::table('change')->count() === $beforeConcurrent, 'Media rejects stale approved snapshot without removing concurrent facts');
check(DB::table('individuals')->where('i_id','I1')->value('i_gedcom') === $accepted, 'Concurrent approved data remains intact');
Registry::$records['I1'] = new GedcomRecord('I1', $accepted, $tree);
$freshMerge = $modify->handle($mergeRequest->withQueryParams(['tree'=>'test', 'xref'=>'I1', 'gedcom'=>'1 NOTE Added', 'mode'=>'merge']));
check($freshMerge->getStatusCode() === 202 && str_contains(Registry::$records['I1']->gedcom(), '1 OCCU Farmer'), 'Reloaded approved snapshot can merge safely');
approved();

$prepared = RecordBatch::prepare([
    ['id' => 'person', 'record-type' => 'INDI', 'gedcom' => "1 NAME Test\n1 FAMS @family@\n1 SOUR @source@"],
    ['id' => 'family', 'record-type' => 'FAM', 'gedcom' => '1 HUSB @person@'],
    ['id' => 'source', 'record-type' => 'SOUR', 'gedcom' => '1 TITL Register'],
]);
check(RecordBatch::resolve($prepared['person']['gedcom'], ['family' => 'F1', 'source' => 'S1']) === "1 NAME Test\n1 FAMS @F1@\n1 SOUR @S1@", 'Batch resolves both family and source pointers');
rejects(fn () => RecordBatch::prepare([['id' => 'one', 'record-type' => 'INDI', 'gedcom' => '0 @I1@ INDI']]), 400);
rejects(fn () => RecordBatch::prepare([['id' => 'one', 'record-type' => 'INDI', 'gedcom' => "1 NAME Test\n3 NOTE Invalid"]]), 400);
rejects(fn () => RecordBatch::prepare([['id' => 'one', 'record-type' => 'INDI'], ['id' => 'one', 'record-type' => 'FAM']]), 400);

$create = new CreateRecords($trees);
$createRequest = (new ServerRequest('GET', ''))->withQueryParams(['tree' => 'test', 'idempotency-key' => 'batch-17-001', 'records' => [
    ['id' => 'person', 'record-type' => 'INDI', 'gedcom' => "1 NAME Test\n1 FAMS @family@\n1 SOUR @source@"],
    ['id' => 'family', 'record-type' => 'FAM', 'gedcom' => '1 HUSB @person@'],
    ['id' => 'source', 'record-type' => 'SOUR', 'gedcom' => '1 TITL Register'],
]]);
$countBeforeCreate = DB::table('change')->count();
\Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess::$write = false;
check($create->handle($createRequest)->getStatusCode() === 403 && DB::table('change')->count() === $countBeforeCreate, 'Batch write access denied before allocation');
\Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess::$write = true;
$missingReference = $createRequest->withQueryParams(['tree' => 'test', 'idempotency-key' => 'batch-missing-17', 'records' => [['id' => 'person', 'record-type' => 'INDI', 'gedcom' => '1 SOUR @Missing@']]]);
check($create->handle($missingReference)->getStatusCode() === 404 && DB::table('change')->count() === $countBeforeCreate, 'Missing external reference rejected before allocation');
$createdResponse = $create->handle($createRequest->withParsedBody($createRequest->getQueryParams())->withQueryParams([]));
$created = json_decode((string) $createdResponse->getBody(), true);
check($createdResponse->getStatusCode() === 202 && count($created['xrefs']) === 3, 'Generic batch creates person, family and source together: ' . $createdResponse->getBody());
check(count($created['records']) === 3 && strlen($created['hash']) === 64, 'Batch returns every record version');
check(DB::table('change')->count() === $countBeforeCreate + 3, 'One complete creation change per batch record, no skeletons');
foreach ($created['records'] as $item) {
    $change = DB::table('change')->where('xref', $item['xref'])->first();
    check($change->old_gedcom === '' && str_contains($change->new_gedcom, "\n1 CHAN\n2 DATE ") && str_contains($change->new_gedcom, '2 _WT_USER editor'), 'Batch preserves native creation audit fields');
}
$personGedcom = DB::table('change')->where('xref', $created['xrefs']['person'])->orderByDesc('change_id')->value('new_gedcom');
check(str_contains($personGedcom, '@' . $created['xrefs']['family'] . '@') && str_contains($personGedcom, '@' . $created['xrefs']['source'] . '@'), 'Persisted batch uses allocated crosslinks');
$count = DB::table('change')->count();
$replay = json_decode((string) $create->handle($createRequest)->getBody(), true);
check($replay['idempotent'] === true && $replay['xrefs'] === $created['xrefs'] && DB::table('change')->count() === $count, 'Pending batch replay creates no records');
$changedRequest = $createRequest->withQueryParams(array_replace($createRequest->getQueryParams(), ['records' => [['id' => 'other', 'record-type' => 'INDI', 'gedcom' => '1 NAME Different']]]));
check($create->handle($changedRequest)->getStatusCode() === 409, 'Same batch key with different payload conflicts');
DB::table('change')->update(['status' => 'accepted']);
$approvedReplay = json_decode((string) $create->handle($createRequest)->getBody(), true);
check($approvedReplay['idempotent'] === true && $approvedReplay['xrefs'] === $created['xrefs'], 'Batch replay works after approval');
// Force final write failure after allocating all records (using a SQLite trigger).
DB::connection()->statement("CREATE TRIGGER fail_batch BEFORE INSERT ON change WHEN NEW.new_gedcom LIKE '%Fail final%' BEGIN SELECT RAISE(ABORT, 'injected'); END");
$count = DB::table('change')->count();
$failure = $createRequest->withQueryParams(['tree' => 'test', 'idempotency-key' => 'batch-17-fail', 'records' => [['id' => 'one', 'record-type' => 'INDI', 'gedcom' => '1 NAME Good'], ['id' => 'two', 'record-type' => 'SOUR', 'gedcom' => '1 TITL Fail final']]]);
check($create->handle($failure)->getStatusCode() === 500 && DB::table('change')->count() === $count, 'Generic batch rolls back all allocated creations and updates on final failure');
DB::connection()->statement('DROP TRIGGER fail_batch');

$verify = new VerifyWrite($trees);
$readRequest = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['api_read_member'])->withQueryParams(['tree' => 'test', 'xref' => $created['xrefs']['person']]);
$verified = json_decode((string) $verify->handle($readRequest)->getBody(), true);
check($verified['matches'] === null, 'Hashless verify-write never claims confirmation');
$withHash = $readRequest->withQueryParams($readRequest->getQueryParams() + ['hash' => $created['records'][0]['hash']]);
check(json_decode((string) $verify->handle($withHash)->getBody(), true)['matches'] === true, 'Write receipt hash confirms current record');
check(json_decode((string) $verify->handle($withHash->withQueryParams(array_replace($withHash->getQueryParams(), ['hash' => str_repeat('0', 64)])))->getBody(), true)['matches'] === false, 'Wrong expected hash is not confirmed');

DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'CANCEL1', 'status' => 'rejected', 'old_gedcom' => '', 'new_gedcom' => '0 @CANCEL1@ INDI']);
DB::table('change')->insert(['gedcom_id' => 1, 'xref' => 'I1', 'status' => 'rejected', 'old_gedcom' => '', 'new_gedcom' => '0 @I1@ INDI']);
$cancelled = new CancelledXrefs($trees);
$historyRequest = $readRequest->withQueryParams(['tree' => 'test', 'limit' => 1]);
$history = json_decode((string) $cancelled->handle($historyRequest)->getBody(), true);
check($history['cancelled-xrefs'][0]['xref'] === 'CANCEL1' && $history['next-offset'] === 1 && $history['history-complete'] === false, 'Cancelled creations are enumerable with honest history coverage');
$next = json_decode((string) $cancelled->handle($historyRequest->withQueryParams(['tree' => 'test', 'limit' => 1, 'offset' => 1]))->getBody(), true);
check($next['cancelled-xrefs'] === [], 'Cancelled history excludes active/reused xref');
\Fisharebest\Webtrees\Auth::$manager = false;
check($cancelled->handle($historyRequest)->getStatusCode() === 403, 'Cancelled history requires tree manager');
\Fisharebest\Webtrees\Auth::$manager = true;
check($cancelled->handle($historyRequest->withAttribute('oauth_scopes', ['api_read_privacy']))->getStatusCode() === 403, 'Cancelled history excludes privacy-only access');
