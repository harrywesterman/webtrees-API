<?php
declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation;

use DomainException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Local private spool. All workers for an installation must share this directory.
 * A single lock covers quota reservation, chunk appends and commit/receipt persistence.
 * Timestamped IDs prevent expired sessions from ever being recreated after cleanup.
 */
final class MediaChunkStore
{
    public const int CHUNK_LIMIT = 262144;
    public const int TTL = 3600;
    public const int RECEIPT_TTL = 86400;
    public const int GLOBAL_BYTES = 268435456;
    public const int OWNER_BYTES = 41943040;
    public const int GLOBAL_IDS = 4096;
    public const int OWNER_IDS = 128;

    public function __construct(private string $directory = '')
    {
        if ($this->directory === '') {
            $this->directory = sys_get_temp_dir() . '/webtrees-mcp-media-' . hash('sha256', dirname(__DIR__, 3));
        }
    }

    public function accept(string $identity, array $input, callable $commit): ResponseInterface
    {
        [$id, $metadata, $bytes] = $this->validate($input);
        if ($identity === '') { throw new DomainException('Authenticated token identity required.', 403); }
        $owner = hash('sha256', $identity);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create private upload storage.');
        }
        clearstatcache(true, $this->directory);
        if (is_link($this->directory) || (fileperms($this->directory) & 0777) !== 0700) {
            throw new RuntimeException('Upload storage must be a private directory (0700).');
        }
        $lock = fopen($this->directory . '/store.lock', 'c+b');
        if ($lock === false) { throw new RuntimeException('Cannot open upload lock.'); }
        chmod($this->directory . '/store.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock upload storage.'); }
            $now = time();
            $all = [];
            foreach (glob($this->directory . '/*.json') ?: [] as $path) {
                $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                if ($state['expires'] <= $now) {
                    $data = substr($path, 0, -5) . '.data';
                    if (is_file($data) && !unlink($data)) { throw new RuntimeException('Cannot expire upload data.'); }
                    if (!unlink($path)) { throw new RuntimeException('Cannot expire upload state.'); }
                    continue;
                }
                // Recover cleanup interrupted after saving a final response.
                if ($state['status'] === 'done') {
                    $data = substr($path, 0, -5) . '.data';
                    if (is_file($data) && !unlink($data)) { throw new RuntimeException('Cannot clean completed upload.'); }
                    if ($state['reserved'] !== 0) {
                        $state['reserved'] = 0;
                        $this->save($path, $state);
                    }
                }
                $all[basename($path, '.json')] = $state;
            }
            $path = $this->directory . '/' . $id;
            $state = $all[$id] ?? null;
            if ($state !== null && !hash_equals($state['owner'], $owner)) {
                throw new DomainException('Upload belongs to another token.', 403);
            }
            if ($state === null) {
                $created = (int) hexdec(substr($id, 0, 8));
                if ($created + self::TTL <= $now) {
                    throw new DomainException('Upload ID expired. Verify any prior media result before starting a new upload ID.', 410);
                }
                if ($created > $now + 300) { throw new DomainException('Upload ID timestamp is more than 300 seconds in the future.', 400); }
                if ($input['offset'] !== 0) { throw new DomainException('Unknown upload; first offset must be zero.', 409); }
                $ownerStates = array_filter($all, fn ($s) => $s['owner'] === $owner);
                $reserved = fn ($states) => array_sum(array_map(fn ($s) => $s['reserved'] ?? 0, $states));
                if (count($all) >= self::GLOBAL_IDS || count($ownerStates) >= self::OWNER_IDS ||
                    $reserved($all) + $metadata['total-bytes'] > self::GLOBAL_BYTES ||
                    $reserved($ownerStates) + $metadata['total-bytes'] > self::OWNER_BYTES) {
                    throw new DomainException('Upload storage quota reached. Wait for existing uploads or receipts to expire.', 429);
                }
                $state = ['owner' => $owner, 'metadata' => $metadata, 'expires' => $created + self::TTL,
                    'status' => 'receiving', 'offset' => 0, 'reserved' => $metadata['total-bytes']];
                $this->save($path . '.json', $state);
            }
            if ($state['metadata'] !== $metadata) { throw new DomainException('Upload metadata cannot change.', 409); }
            if ($state['status'] === 'committing') {
                throw new DomainException('Upload commit outcome is uncertain. Do not retry with a new ID; verify the media record with an administrator.', 409);
            }
            $fingerprint = hash('sha256', json_encode([$input['offset'], $input['final'], $input['content-base64']], JSON_THROW_ON_ERROR));
            if ($state['status'] === 'done') {
                if ($state['final-request'] !== $fingerprint) { throw new DomainException('Upload already finalized; replay the exact final request.', 409); }
                return new Response($state['response']['status'], ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store'], $state['response']['body']);
            }
            $data = fopen($path . '.data', 'c+b');
            if ($data === false) { throw new RuntimeException('Cannot open upload data.'); }
            chmod($path . '.data', 0600);
            try {
                $size = fstat($data)['size'];
                // A crash after append but before the durable offset is recoverable.
                if ($size < $state['offset']) { throw new DomainException('Upload data lost; refusing to commit.', 409); }
                if ($size > $state['offset'] && !ftruncate($data, $state['offset'])) { throw new RuntimeException('Cannot recover upload append.'); }
                $offset = $input['offset'];
                $end = $offset + strlen($bytes);
                if ($offset > $state['offset'] || ($offset < $state['offset'] && $end > $state['offset'])) {
                    throw new DomainException('Out-of-order chunk; resume at acknowledged next-offset.', 409);
                }
                if (fseek($data, $offset) !== 0) { throw new RuntimeException('Cannot seek upload.'); }
                if ($offset < $state['offset']) {
                    if (stream_get_contents($data, strlen($bytes)) !== $bytes) { throw new DomainException('Conflicting chunk replay.', 409); }
                } else {
                    $this->write($data, $bytes);
                    if (!fflush($data) || !fsync($data)) { throw new RuntimeException('Cannot persist chunk.'); }
                    $state['offset'] = $end;
                    $this->save($path . '.json', $state);
                }
            } finally { fclose($data); }
            if (!$input['final']) {
                return new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store'], json_encode([
                    'upload-id' => $id, 'next-offset' => $state['offset'], 'total-bytes' => $metadata['total-bytes'],
                    'complete' => false, 'expires-at' => $state['expires'],
                ], JSON_THROW_ON_ERROR));
            }
            if ($state['offset'] !== $metadata['total-bytes'] || $end !== $metadata['total-bytes']) {
                throw new DomainException('Final chunk must end at total-bytes.', 409);
            }
            if (!hash_equals($metadata['sha256'], hash_file('sha256', $path . '.data'))) {
                throw new DomainException('Upload SHA-256 mismatch.', 400);
            }
            $file = new UploadedFile($path . '.data', $metadata['total-bytes'], UPLOAD_ERR_OK, $metadata['filename']);
            // Persist intent BEFORE any external/DB write. Never automatically repeat this state.
            $state['status'] = 'committing';
            $state['expires'] = $now + self::RECEIPT_TTL;
            $this->save($path . '.json', $state);
            $response = $commit($file, $metadata);
            if ($response->getStatusCode() >= 500) {
                return $response; // Keep committing marker: a failure can follow a successful DB commit.
            }
            $state['status'] = 'done';
            $state['final-request'] = $fingerprint;
            $state['response'] = ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
            $state['expires'] = $now + self::RECEIPT_TTL;
            $this->save($path . '.json', $state);
            if (!unlink($path . '.data')) { throw new RuntimeException('Cannot remove completed upload spool.'); }
            $state['reserved'] = 0;
            $this->save($path . '.json', $state);
            return $response;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validate(array $input): array
    {
        $allowed = ['upload-id', 'offset', 'content-base64', 'total-bytes', 'sha256', 'filename', 'tree', 'target-xref', 'target-type', 'title', 'note', 'date', 'final'];
        if (array_diff(array_keys($input), $allowed)) { throw new DomainException('Unknown chunk upload field.', 400); }
        $id = $input['upload-id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-fA-F0-9]{32}$/D', $id)) {
            throw new DomainException('upload-id must be 8 hexadecimal Unix timestamp digits followed by 24 random hexadecimal digits.', 400);
        }
        if (!is_int($input['offset'] ?? null) || $input['offset'] < 0 || !is_int($input['total-bytes'] ?? null) || !is_bool($input['final'] ?? null)) {
            throw new DomainException('offset and total-bytes must be integers; final must be boolean.', 400);
        }
        if ($input['total-bytes'] < 1 || $input['total-bytes'] > MediaInput::REST_LIMIT) { throw new DomainException('total-bytes must be 1..20971520.', 413); }
        if (!is_string($input['sha256'] ?? null) || !preg_match('/^[a-fA-F0-9]{64}$/D', $input['sha256'])) { throw new DomainException('sha256 must contain 64 hexadecimal digits.', 400); }
        $encoded = $input['content-base64'] ?? null;
        if (!is_string($encoded)) { throw new DomainException('content-base64 must be a string.', 400); }
        if (strlen($encoded) > 349528) { throw new DomainException('Chunk exceeds 256 KiB.', 413); }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded) { throw new DomainException('Chunk must be canonical base64.', 400); }
        if (strlen($bytes) > self::CHUNK_LIMIT) { throw new DomainException('Chunk exceeds 256 KiB.', 413); }
        if (($bytes === '' && !$input['final']) || $input['offset'] > $input['total-bytes'] || strlen($bytes) > $input['total-bytes'] - $input['offset']) {
            throw new DomainException('Chunk is empty or exceeds declared total-bytes.', 400);
        }
        $metadata = ['total-bytes' => $input['total-bytes'], 'sha256' => strtolower($input['sha256'])];
        foreach (['filename', 'tree', 'target-xref', 'target-type', 'title', 'note', 'date'] as $key) {
            $metadata[$key] = MediaInput::text($input, $key);
        }
        MediaInput::filename($metadata['filename']);
        if ($metadata['tree'] === '' || strlen($metadata['tree']) > 255 || !preg_match('/^[A-Za-z0-9_:-]{1,64}$/D', $metadata['target-xref']) || !in_array($metadata['target-type'], ['INDI', 'FAM', 'SOUR'], true)) {
            throw new DomainException('Valid tree, target-xref and target-type are required.', 400);
        }
        return [strtolower($id), $metadata, $bytes];
    }

    private function save(string $path, array $state): void
    {
        $file = fopen($path . '.new', 'wb');
        if ($file === false) { throw new RuntimeException('Cannot persist upload state.'); }
        chmod($path . '.new', 0600);
        try {
            $this->write($file, json_encode($state, JSON_THROW_ON_ERROR));
            if (!fflush($file) || !fsync($file)) { throw new RuntimeException('Cannot flush upload state.'); }
        } finally { fclose($file); }
        if (!rename($path . '.new', $path)) { throw new RuntimeException('Cannot publish upload state.'); }
        // Also persist the rename, so a crash cannot forget the committing marker.
        $directory = fopen($this->directory, 'r');
        if ($directory === false) { throw new RuntimeException('Cannot open spool directory.'); }
        try { if (!fsync($directory)) { throw new RuntimeException('Cannot flush spool directory.'); } }
        finally { fclose($directory); }
    }

    private function write($file, string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = fwrite($file, substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Upload storage is full.'); }
        }
    }
}
