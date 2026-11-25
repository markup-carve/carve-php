// Seeded SVG variants for parity.json: `node variants.mjs <seed> <count>` prints {src, opts} rows; each row's
// svg/ok is carve-js `sanitizeSvg` output (next2 ba51e9e5). The "carve-js test" rows are the inputs its own tests
// pass; the carve#6755 rows expect carve-js output with the root xmlns fix.
let seed = Number(process.argv[2] ?? 1)
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff }
const pick = (a) => a[Math.floor(rnd() * a.length)]
const maybe = (p, s, e = '') => (rnd() < p ? s : e)
const WS = [' ', '  ', '\t', '\n', '  ', ' ', '　 ', '﻿ ', '\r\n']
const SCHEMES = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'jav&#x61;script:alert(1)', 'jav&#97;script:x', 'javascript&colon;x',
  '&#106;avascript:x', 'java\tscript:x', 'java&#x9;script:x', 'java&tab;script:x', ' javascript:x', ' javascript:x', 'java\u007fscript:x',
  'java\u0085script:x', 'java​script:x', 'java﻿script:x', 'java&#xD800;script:x', '&#xD800;javascript:x', 'java\\73 cript:x',
  'vbscript:x', 'data:image/svg+xml,<svg onload=alert(1)>', 'DATA:text/html,x', 'file:///etc/passwd', 'ms-msdt:x', 'search-ms:x', 'vscode:x',
  'https://ok.example/a', 'http://ok.example/', 'mailto:a@b', '//evil.example/x', '/abs/path', 'rel/path', '#frag', '#x', '', 'tel:1',
  'x-custom:1', 'https&colon;//evil', 'java&NewLine;script:x', 'java&newline;script:x', '&#0000106;avascript:x', '&#x00006A;avascript:x',
  '&#99999999;x', '&#x110000;x', 'java script:x', 'javascript　:x', 'jar:file:x', 'shell:x', 'ms-cxh-full:x']
const URLVALS = ['url(#g)', "url('#g')", 'url( #g)', 'url(https://evil/p#x)', 'url("https://evil/a)b#x")', 'url(//evil/c)', 'u\\72l(https://e/x)',
  'u&#114;l(https://e)', 'red', '#fff', 'none', 'currentColor', 'url(#a) red', '#a;https://evil/x', '#a;//evil', '0;1;javascript:x', '1;2;3', '/x',
  'javascript:x', 'URL(https://e)', 'url(\n#a)', 'rgb(1,2,3)', '&#x2f;&#x2f;evil', 'u\\rl(x)', 'url(data:x)']
const STYLES = ['fill:red', 'fill:url(#a)', 'background:url(javascript:alert(1))', 'fill:u\\72l(https://e/x)', 'x:expression(alert(1))',
  'behavior:url(x.htc)', '-moz-binding:url(x)', '@import "x"', 'color:red/*x*/', 'b/**/ehavior:x', 'width:1px;JAVASCRIPT:x', 'e&#x78;pression(1)',
  'font-family:"a b"', 'fill:Kelvin', 'u\\0000072l(x)', 'url (x)']
const ATTRS = ['d', 'x', 'y', 'width', 'height', 'viewBox', 'VIEWBOX', 'fill', 'stroke', 'transform', 'class', 'id', 'role', 'data-x', 'aria-label',
  'DATA-Y', 'xml:space', 'xmlns:xlink', 'version', 'foo', 'tabindex', 'begin', 'values', 'from', 'to', 'by', 'attributeName', 'filter', 'mask',
  'clip-path', 'marker-end', 'stop-color', 'color', 'opacity', 'type', 'in', 'result', 'style', 'href', 'xlink:href', 'XLINK:HREF', 'HREF',
  'src', 'formaction', 'onload', 'onclick', 'ONLOAD', 'OnMouseOver', 'onbegin', 'on', 'ev:event', 'xmlns:ev', 'srcdoc', 'x:onload', 'font-family']
const TAGS = ['g', 'rect', 'circle', 'path', 'text', 'tspan', 'use', 'defs', 'symbol', 'linearGradient', 'lineargradient', 'stop', 'filter', 'feImage',
  'feGaussianBlur', 'clipPath', 'mask', 'pattern', 'marker', 'title', 'desc', 'switch', 'a', 'A', 'image', 'IMAGE', 'animate', 'animateTransform',
  'animateMotion', 'set', 'mpath', 'script', 'SCRIPT', 'foreignObject', 'foreignobject', 'style', 'iframe', 'img', 'body', 'handler', 'discard',
  'metadata', 'svg', 'Rect', 'x:script', 'font', 'view']
const TEXTS = ['hi', 'a & b', 'a &amp; b', '&nbsp;', '&copy;', '&#169;', '&#xA9;', '&lt;script&gt;', '1 < 2', 'x > y', '"q"', "'q'", 'ünï',
  '😀', '&unknown;', '&#;', '&', 'alert(1)', ' ', 'x y']
const quoteVal = (v) => { const q = rnd(); return q < 0.55 ? `"${v.replace(/"/g, '&quot;')}"` : q < 0.85 ? `'${v.replace(/'/g, '&apos;')}'` : (/[\s>"'`=]/.test(v) || v === '' ? `"${v}"` : v) }
function attr(tag) {
  const name = pick(ATTRS)
  const r = rnd()
  if (r < 0.06) return name
  let v
  if (/href|src/i.test(name)) v = pick(SCHEMES)
  else if (/style/i.test(name)) v = pick(STYLES)
  else if (/^(fill|stroke|filter|mask|clip-path|marker-end|stop-color|color|values|from|to|by)$/i.test(name)) v = pick(URLVALS)
  else if (/^on/i.test(name)) v = 'alert(1)'
  else v = pick([...URLVALS.slice(0, 6), '10', '0 0 10 10', 'a b', 'x"y', "x'y", 'a<b>', 'a&b', 'a&amp;b', '&#x6A;avascript:x', 'javascript:x', 'translate(1 2)', pick(SCHEMES)])
  const eq = pick(['=', '=', ' = ', '\t=\n', '= '])
  return `${name}${eq}${quoteVal(v)}`
}
function attrs(tag) { let s = ''; const n = Math.floor(rnd() * 4); for (let i = 0; i < n; i++) s += pick(WS) + attr(tag); return s + maybe(0.1, pick(WS)) }
function node(depth) {
  const r = rnd()
  if (depth > 4 || r < 0.2) return pick(TEXTS)
  if (r < 0.25) return pick(['<!-- c -->', '<!-- <script>x</script> -->', '<![CDATA[<script>alert(1)</script>]]>', '<![CDATA[a & b]]>', '<?xml version="1.0"?>', '<?pi x?>', '<!DOCTYPE x>', '<!ENTITY x "y">'])
  const tag = pick(TAGS)
  if (rnd() < 0.3) return `<${tag}${attrs(tag)}${pick(['/>', ' />'])}`
  let kids = ''; const n = Math.floor(rnd() * 3); for (let i = 0; i < n; i++) kids += node(depth + 1)
  return `<${tag}${attrs(tag)}>${kids}</${tag}${maybe(0.1, ' ')}>`
}
function rootAttrs() {
  let s = attrs('svg')
  if (rnd() < 0.3) s += ' ' + pick(['xmlns="http://www.w3.org/2000/svg"', "xmlns='http://www.w3.org/2000/svg'", 'XMLNS="x"', 'xmlns:xlink="http://www.w3.org/1999/xlink"', 'xmlns=http://www.w3.org/2000/svg', 'viewBox="0 0 1 1"'])
  return s
}
const OPTS = [{}, {}, { allowLinks: true }, { allowStyle: true }, { allowAnimation: true }, { allowExternalImages: true },
  { allowLinks: true, allowStyle: true, allowAnimation: true, allowExternalImages: true },
  { allowLinks: true, allowExternalImages: true, urlPolicy: { allow: ['https'] } }, { allowLinks: true, urlPolicy: { deny: [] } },
  { allowLinks: true, urlPolicy: { deny: ['HTTPS', 'mailto'] } }]
const out = []
const n = Number(process.argv[3] ?? 1000)
for (let i = 0; i < n; i++) {
  let kids = ''; const k = 1 + Math.floor(rnd() * 3); for (let j = 0; j < k; j++) kids += node(1)
  let src = `<svg${rootAttrs()}>${kids}</svg>`
  const m = rnd()
  if (m < 0.05) src = src.slice(0, Math.floor(rnd() * src.length))       // truncated
  else if (m < 0.1) src = pick(WS) + src + pick(WS)                        // padded
  else if (m < 0.13) src = 'x' + src                                       // text before root
  else if (m < 0.16) src = src + '<svg/>'                                  // second root
  else if (m < 0.19) src = `<svg${rootAttrs()}/>`                          // self-closed root
  out.push({ src, opts: pick(OPTS) })
}
process.stdout.write(JSON.stringify(out))
