# Media repair review and deployment record

The repair is committed and deployed. The current fork commit is `53491a4` (`Return clear errors for truncated MCP bodies`), following `b717d2f` (`Fix media REST transport and MCP body limits`). It is active at DreamHost on `2026-09-14`.

Deployment target: `https://www.stamboomwesterman.net/`, webtrees `2.2.6`, module `1.3.0-beta`. The previous module and a database/settings/key backup remain in the private deployment directory on the server. No DreamHost PHP or webtrees configuration was changed.

## Review order

1. `src/Http/RequestHandlers/Media.php`: scope checks, shared operations, pending-change protection, transactions, retained files on deletion.
2. `src/Http/Validation/MediaInput.php`: content decoding, size/memory limits, safe paths, GEDCOM continuation and field preservation.
3. Middleware and the six MCP adapters: preserved multipart/methods, discovery, scope lists, 202 success and omission of raw payloads from debug logs.
4. `src/Http/Schema/MediaTools.php`: shared tool and OpenAPI definitions. Runtime regeneration merges these definitions; tests compare the checked-in JSON.
5. `resources/media-api.md`: English/Dutch operation instructions and Codex/OpenCode examples, including client payload limitations.

## Deliberate behavior

- JPEG/PNG/GIF/WebP only, 5 MiB MCP / 20 MiB REST, 40 megapixels and available decoding memory. TIFF/PDF/SVG are not accepted.
- Random per-upload directories keep repeated filenames from overwriting files. Do not blindly retry uploads.
- Media and target links both await review. Further writes refuse pending edits and restricted facts.
- Deletion is a pending record deletion, not immediate file destruction. Shared files and rejected changes retain their bytes. Administrator cleanup is separate.
- `_DATE` is a webtrees-supported custom media date field. Notes and titles preserve omitted values and reject ambiguous multiple values.
- The lockfile's old helper version lacked `registerRoute`. Only that package was updated (1.2.45 to 1.4.60). PHP 8.4 now matches the README/locked dependencies; GD and fileinfo are explicit requirements.

## Verification and remaining limits

- PHP 8.5.5 on DreamHost passed syntax checks for 110 PHP source/test files. The isolated behavior suite passed 75 checks and the real webtrees/MCP contract suite passed 97 checks.
- Handler tests use real PSR-7, Flysystem and SQLite transactions, with webtrees record/access doubles. They cover upload targets, MIME/extension/path checks, scopes/privacy, rollback, pending conflicts, metadata, link/unlink, downloads and file retention.
- Contract tests load webtrees 2.2.6 and module dependencies. They cover real method availability, middleware, tools/list, dispatch, scope classification, 202 MCP results and checked-in OpenAPI consistency.
- Composer validation succeeds with existing-style open-ended version warnings; dependency resolution reports no vulnerability advisories. The temporary PHP build lacks sodium, so dependency installation ignored that platform requirement for this local test environment only. Production requirements are not bypassed in the repository.
- No production database records or images were changed by deployment. A deliberately invalid upload was rejected. Live MCP discovery returned 20 tools including all six media tools; live REST multipart POST reached the handler without a 302 redirect; a large truncated MCP body returned HTTP 413 with `receivedBodyBytes`. A 2.3 runtime and an actual approved-image upload were not exercised.
- DreamHost reports PHP `post_max_size` and `upload_max_filesize` as 512M, but its shared web path currently truncates no-file JSON bodies at about 2.5 MiB. The module now returns a clear 413 for detectable truncation. Use multipart REST for larger images; the documented 5 MiB MCP limit remains subject to the upstream transport.
- OAuth client settings, access tokens, encryption key, and public/private key hashes were unchanged. The technical user retains editor access to six trees and has no explicit automatic-accept setting.
- The per-tree lock serializes these media API writes, not unrelated webtrees editor operations. PHP/filesystem failure after a process crash can leave an unused file for cleanup; filesystem/database operations cannot be one atomic transaction.
- Image bytes remain intact. This does not remove EXIF metadata or provide malware scanning. Authorization and privacy should be checked again in the eventual staging acceptance test.
