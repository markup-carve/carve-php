# Developer documentation

[CONTRIBUTING.md](../CONTRIBUTING.md) covers local setup, tests, coding
standards, static analysis and changes that affect the specification.
For application integration, see the [PHP guides](README.md).

## Custom renderers

Custom renderers implement `RendererInterface`. Add `RenderTargetInterface` and
`RenderLossAwareRendererInterface` to support `convertWithReport()` and
`renderWithReport()`. `getRenderTarget()` names the target in each loss report.
`RenderTarget` defines the built-in names, including `RenderTarget::HTML`;
custom renderers can return their own target string.
Optional capabilities expose safe mode, typography, render mode, static renderers,
render events, symbols, and heading IDs; the converter uses those interfaces
when configuring a renderer. `StaticRenderExtensionsInterface` lets a renderer
register static HTML extension hooks; their existing contract receives an
`HtmlRenderer`, so a composed renderer can delegate to its HTML backend.
Extensions that explicitly require `HtmlRenderer` still require that class.
See the interfaces in [src/Renderer](../src/Renderer).

## Implementation details

- [Integrated definition layout](integrated-definition-layout.md): reference, footnote and abbreviation resolution.
- [HTML whitespace differences](html-whitespace.md): handling empty raw blocks.
- [Configured conversion fast path](configured-conversion-fast-path.md): converter reuse.
- [Extension matchers](extensions.md#extension-matchers): custom parse-stage matchers.

## Performance

- [Parser and renderer measurements](performance/parser-renderer.md): paired results with and without tracing JIT.
- [Bracket scan measurements](performance/brackets.md): parser timings and reproduction.
