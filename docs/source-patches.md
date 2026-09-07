# Source-preserving patches

Use a source patch when a tool needs a stale-safe structured formatting change:

```php
$patch = CarveConverter::toCarvePatch($source);
$formatted = $patch->apply($source);
```

`SourcePatch::create()` also prepares a patch for an arbitrary replacement.
Ranges are half-open UTF-8 byte offsets. A byte length and stable `fnv1a64:` fingerprint
prevent application to stale source; edits must be sorted, non-overlapping
character boundaries.

See the [shared contract](https://markup-carve.github.io/carve/source-patches).
