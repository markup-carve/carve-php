# AST editor sidecars

The sidecar APIs use canonical AST arrays from `AstCodec`. Sidecars travel beside the AST and are not written into Carve source.

`CarveConverter::parseWithNodeIdentity($source, $session)` returns an AST and an identity sidecar. Keep one `NodeIdentitySession` for an editor session. On a later parse, call `emit($ast, $retainedPaths)` to bind recognized old IDs to their new JSON Pointer paths. Unmapped nodes keep their IDs only when their semantic values remain at the same paths. Source positions do not affect this match. The session mints fresh IDs for every other node. `NodeIdentitySession::read($ast, $sidecar)` checks a received sidecar, including its version, pointers, and uniqueness.

`AnnotationRanges::create($ast, $ranges)` and `Provenance::create($ast, $sources, $nodes)` validate caller supplied editor and import metadata. Their `read()` methods validate received sidecars. Ranges may cross node boundaries and overlap. Offsets count Unicode codepoints in a node's text. Provenance byte ranges address the named input and use exclusive ends. `CarveConverter::parseWithProvenance($source, $uri)` records spans for a directly parsed Carve source.

`AstPatch::createReversible($before, $after)` returns forward and inverse operations with fingerprints. Apply either direction with `AstPatch::applyReversible($document, $patch, $inverse)`. It rejects a document whose semantic AST does not match the patch's expected revision. Positions are excluded from the fingerprints, as in ordinary AST patches.
