# Stored documents and spec versions


`carve fmt --stamp` records the spec version a document was last processed under:

~~~
%% carve-version: 0.1; generated-by: carve-php 0.1.6
~~~

That marker is what makes the spec's
[upgrade procedure](https://markup-carve.github.io/carve/versioning) actionable -
when moving a stored document to a newer spec version you only review the
`[behavior]` changelog entries between its stamped version and the target. Read
it back with:

~~~ php
use MarkupCarve\Carve\Stamp;

Stamp::read($source);          // ['version' => '0.1', 'generatedBy' => 'carve-php 0.1.6'] or null
Stamp::needsReview($source);   // true when the document predates this engine's spec version
~~~

An unstamped document answers `needsReview() === true`: its provenance is
unknown, and assuming it is current is the unsafe direction. From the CLI:

~~~ bash
bin/carve --stamp-info doc.crv    # report version and writer
bin/carve --stamp-check doc.crv   # exit 1 when the document predates this spec version
~~~

`--stamp-check` is meant for a repository of stored `.crv` files: run it over the
directory in CI and a document left behind by a spec upgrade fails the build
instead of silently rendering differently.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
