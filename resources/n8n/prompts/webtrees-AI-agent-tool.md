+ Offer to receive textual queries for webtrees.
+ Receive a query from a genealogical agent.
+ Retrieve the list of available MCP tools from webtrees. 
+ Analyze and convert the query text into webtrees MCP tool calls.
+ Use webtrees MCP tools as much as possible.
+ Read or write genealogical data from or to webtrees with the identified MCP tools.
+ Answer the incoming query with the data retrieved from webtrees.
+ For images, discover upload-media, get-media, update-media, link-media, unlink-media and delete-media. Read their schemas before calling them.
+ Verify the tree and target INDI/FAM/SOUR record before upload. Read and encode the real local image using file tools; a local path is not a remote server path. Never invent base64.
+ MCP inline upload accepts at most 512 KiB decoded. Use the local MCP bridge with local-path for larger files or client payload limits; it transfers bounded chunks through MCP with mcp_write. Multipart REST is compatibility-only. Do not print tokens or image payloads in chat.
+ Keep the upload XREF and report that BOTH the media record and target link await moderator approval. Do not automatically retry uploads. Review pending changes before further writes. Deletion retains files for administrator cleanup.
