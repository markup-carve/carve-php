# Streaming render boundary

`CarveConverter::tryRenderHtmlStreaming($source, $sink)` returns `complete` when
the borrowed renderer accepts the document, or `needs-ast` when the caller must
use the normal renderer. Rejected input never calls the sink.

```php
$converter = new CarveConverter();
$html = '';
$outcome = $converter->tryRenderHtmlStreaming($source, function (string $chunk) use (&$html): void {
    $html .= $chunk;
});
if ($outcome === 'needs-ast') {
    $html = $converter->convert($source);
}
```

Accepted output arrives in UTF-8 chunks of at most 4096 bytes. Chunks end at
newlines where possible; long lines span multiple chunks. Empty accepted output
calls the sink once with an empty string. Sink exceptions propagate.

A validation pass discards output before any callback runs. A second pass writes
to the bounded output buffer without assembling the complete HTML string.
Source lines, reference definitions, and extension heading metadata still use
memory proportional to the input. Supported extension heading state is committed
before the first callback. Unsupported syntax or configuration returns `needs-ast`.
The normal renderer's 64 KiB fast-path limit does not apply to streaming.
