# Source editing sessions

`CarveConverter::createEditorSession($source)` returns a session with revisioned
snapshots. Ranges use UTF-8 byte offsets. Each snapshot contains the source,
serialized AST, source layout, mapped nodes with syntax tokens, and a node
identity sidecar.

```php
$session = (new CarveConverter())->createEditorSession('hello');
$update = $session->update([['from' => 0, 'to' => 5, 'insert' => 'world']]);
```

Changes must be sorted and non-overlapping, with offsets measured against the
previous snapshot. Invalid ranges, split UTF-8 scalars, and invalid UTF-8 inserts
are rejected before the session changes.
Untouched nodes keep their IDs when their mapped source ranges can be matched.
`changedPaths` includes semantic changes, including links affected by edits to
reference definitions elsewhere in the source.

A single edit inside a single-line plain paragraph reparses only that paragraph
when the document contains only plain paragraphs. The supported text includes
Unicode letters, numbers, combining marks, spaces, and sentence punctuation
(`.`, `,`, `!`, `?`). Other blocks, structural edits, newlines, multiple edits,
and customized parsers, profiles, or extensions use a full parse. The built-in
frontmatter and mention extensions are safe for this restricted text subset.

`reusedPreviousTree` reports whether unchanged blocks were reused.
`parsedSourceBytes` counts bytes sent to the parser, including a failed local
attempt before fallback. Layout, identity matching, and snapshot construction
still traverse the document. Snapshots are returned by value.

An empty edit set reuses an eligible plain-paragraph document without parsing.
Other documents and parser configurations follow the full-parse path.
