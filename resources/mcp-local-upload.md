# Local image uploads entirely over MCP

Run `bin/webtrees-mcp.mjs` with Node.js 22 or newer as the local stdio MCP server.
It forwards the existing webtrees tools and exposes `upload-media` with a
`local-path` argument. The bridge reads the image itself, hashes it, and sends
256 KiB chunks to the authenticated remote `upload-media-chunk` MCP tool.
Neither image bytes nor base64 need to pass through the model. No REST upload
endpoint or `api_write` scope is used. Uploads require `mcp_write` and the normal
technical-user write permissions. Pending reads require `mcp_read_member`.

Example OpenCode configuration for the installed v1 configuration layout:

```json
{
  "mcp": {
    "webtrees-mcp-server": {
      "type": "local",
      "command": ["node", "/absolute/path/webtrees-API/bin/webtrees-mcp.mjs"],
      "enabled": true,
      "timeout": 180000,
      "environment": {
        "WEBTREES_MCP_URL": "https://www.stamboomwesterman.net/mcp",
        "WEBTREES_UPLOAD_ROOTS": "/absolute/path/to/genealogy:/absolute/path/to/Pictures"
      }
    }
  }
}
```

The process inherits `TOKEN` (or `WEBTREES_TOKEN`). Keep the secret out of this
configuration and version control. Roots use the platform path separator (`:`
on macOS/Linux, `;` on Windows); without the variable only the working directory
is allowed. Symlinks are resolved and hidden paths beneath roots are refused.
The remote URL is fixed by configuration, HTTPS-only, and redirects are refused.

Restart the client to load the local bridge. `tools/list` should show
`upload-media` with `local-path`, rather than `content-base64`. Other tools keep
their names. The internal chunk tool is not exposed to the model.

Example instruction:

> Verify the tree and intended target with get-trees/get-record. Upload the local
> file /absolute/path/photo.jpg using upload-media with local-path. Retain the
> returned media XREF and report pending approval. Do not resize, re-encode or
> upload twice. Leave both the media record and target link pending for review.

Files remain limited to 20 MiB, 40 megapixels and valid JPEG/PNG/GIF/WebP content.
The same Media handler performs validation, storage and transactional linking.
An incomplete transfer does not create a media record. A successful transfer
creates pending changes; approve media and target link together in webtrees.
On timeout, inspect the site before starting a new upload: a lost response can
occur after the media was stored.

Verify the local bridge with `node --test tests/mcp-bridge.test.mjs`. Server tests
are in `tests/media-chunks.php` and the existing media test suites. A live test
creates pending records and must use an explicitly selected test target.

Direct remote MCP clients can still use small inline uploads. The multipart
REST route remains available to independent API clients; it is not part of the
local bridge's upload path.
