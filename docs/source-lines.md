# Source-line tracking


For editor previews and scroll sync, enable source-line tracking with
`sourceLines: true`. Rendered HTML block anchors receive a 1-based
`data-source-line` attribute for their start line in the original document.
The attribute is applied to top-level and nested block elements, including
blocks inside block quotes, divs, list items, footnotes, and definition lists,
and to `<li>`, `<dt>`, and `<dd>` elements (endnote `<li>` entries included,
anchored at their definition line). Author-supplied `data-source-line`
attributes are preserved.

~~~ php
$converter = new CarveConverter(sourceLines: true);
$html = $converter->convert("- Item\n\n  More\n");
~~~

~~~ html
<ul data-source-line="1">
  <li data-source-line="1"><p data-source-line="1">Item</p>
<p data-source-line="3">More</p></li>
</ul>
~~~

`data-source-line` is the stable lean source-position tier: the attribute name,
format, 1-based start-line meaning, and block/list/definition scope are frozen.
Richer start/end ranges with columns and byte offsets would be added later as a
separate opt-in option, not folded into this attribute. Any future end
positions must be tight and must not overshoot into separator blank lines.

---

[Back to the README](https://github.com/markup-carve/carve-php/blob/main/README.md)
