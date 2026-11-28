// Load hook for js-trace.mjs: inserts trace calls into carve-js src/write.ts as tsx loads it, so no carve-js file
// changes. tsx minifies whitespace, so the patterns allow any.
const PATCHES = [
  [/(const\s+check\s*=\s*compareWithLinkFallback\(\s*w\s*,\s*document\s*,\s*group\s*,\s*first\s*,\s*initial\s*\)\s*;?)/,
    '$1;globalThis.__plannerTrace?.compared(w,check,group);'],
  [/return\s+applyChanges\(\s*w\s*,\s*check\s*\)\s*;?\s*\}\s*__name\(\s*settle/,
    'const __traced=globalThis.__plannerTrace?.before(w);const __applied=applyChanges(w,check);' +
      'globalThis.__plannerTrace?.placed(w,check,__traced);return __applied}__name(settle'],
]

export async function load(url, context, nextLoad) {
  const result = await nextLoad(url, context)
  if (!url.endsWith('/src/write.ts')) return result
  let source = typeof result.source === 'string' ? result.source : Buffer.from(result.source).toString('utf8')
  for (const [from, to] of PATCHES) {
    if (!from.test(source)) throw new Error(`js-trace: carve-js write.ts no longer matches ${from}`)
    source = source.replace(from, to)
  }
  return { ...result, source }
}
