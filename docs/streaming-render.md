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

Accepted output arrives in newline-terminated chunks, followed by any final
unterminated line. Empty accepted output calls the sink once with an empty
string. Sink exceptions propagate.

The complete HTML string is buffered before delivery. Acceptance depends on
the borrowed renderer's supported syntax and converter configuration. This API
adds callback delivery; unbuffered rendering remains future work.
