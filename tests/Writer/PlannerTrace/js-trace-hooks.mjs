// Load hook for js-trace.mjs: inserts trace calls into carve-js src/write.ts as tsx loads it, so no carve-js file
// changes. tsx minifies whitespace, so the patterns allow any.
const PATCHES = [
  [/(const\s+check\s*=\s*compareWithLinkFallback\(\s*w\s*,\s*document\s*,\s*group\s*,\s*first\s*,\s*initial\s*\)\s*;?)/,
    '$1;globalThis.__plannerTrace?.compared(w,check,group);'],
  [/return\s+applyChanges\(\s*w\s*,\s*check\s*\)\s*;?\s*\}\s*__name\(\s*settle/,
    'const __traced=globalThis.__plannerTrace?.before(w);const __applied=applyChanges(w,check);' +
      'globalThis.__plannerTrace?.placed(w,check,__traced);return __applied}__name(settle'],
  [/function\s+compareGroup\(\s*w\s*,\s*document\s*,\s*group\s*,\s*first\s*\)\s*\{/,
    'function compareGroup(w,document,group,first){const __state=globalThis.__plannerTrace?.state(w,document);'],
  [/return\s+check\s*;?\s*\}\s*__name\(\s*compareGroup/,
    'globalThis.__plannerTrace?.compareDone(w,document,group,first,check,__state);return check}__name(compareGroup'],
]

// JS_TRACE_PROBES=1 (a debug aid): each probe round of write-check.ts prints its node, flags and source on stderr.
const PROBE_PATCHES = [
  [/(function\s+probeRound\([^)]*\)\s*\{\s*const\s+source\s*=\s*`\$\{writeInlines\(\s*w\s*,\s*expected\s*,\s*flags\s*\)\}\n`\s*;?)/,
    '$1;globalThis.__plannerTrace?.probe?.(e,flags,source);'],
  [/(function\s+respell\(\s*c\s*,\s*es\s*,\s*i\s*\)\s*\{)/, '$1globalThis.__plannerTrace?.respell?.(es[i],c.flags);'],
  [/(const\s+own\s*=\s*writeInline\(\s*c\.w\s*,\s*es\[k\]\s*,\s*c\.flags\s*\)\s*;?)/,
    '$1;globalThis.__plannerTrace?.resync?.(k,offset,j,as[j]?.pos?.startOffset,failed.endOffset,own,c.source);'],
]

export async function load(url, context, nextLoad) {
  const result = await nextLoad(url, context)
  if (process.env.JS_TRACE_PROBES === '1' && url.endsWith('/src/write-check.ts')) {
    let source = typeof result.source === 'string' ? result.source : Buffer.from(result.source).toString('utf8')
    for (const [from, to] of PROBE_PATCHES) {
      if (!from.test(source)) throw new Error(`js-trace: carve-js write-check.ts no longer matches ${from}`)
      source = source.replace(from, to)
    }
    return { ...result, source }
  }
  if (!url.endsWith('/src/write.ts')) return result
  let source = typeof result.source === 'string' ? result.source : Buffer.from(result.source).toString('utf8')
  for (const [from, to] of PATCHES) {
    if (!from.test(source)) throw new Error(`js-trace: carve-js write.ts no longer matches ${from}`)
    source = source.replace(from, to)
  }
  return { ...result, source }
}
