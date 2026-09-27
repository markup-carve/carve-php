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
are rejected before the session changes. Updates fully reparse the source.
Untouched nodes keep their IDs when their mapped source ranges can be matched.
`changedPaths` includes semantic changes, including links affected by edits to
reference definitions elsewhere in the source.
