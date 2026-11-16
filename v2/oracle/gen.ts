// Generate the pinned seed corpus with the read-only carve-js checkout.
import { writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
const root = resolve(process.env.CARVE_JS_DIR ?? '../carve-js')
process.chdir(root)
const { random, loadCorpus, generate } = await import(pathToFileURL(resolve(root, 'v2/proptest/generate.ts')).href)
const [seed, count, max, out] = process.argv.slice(2)
if (!out) throw new Error('usage: node gen.ts SEED COUNT MAXBYTES OUT.json')
const corpus = loadCorpus()
const docs: string[] = []
for (let i = 0; i < Number(count); i++) docs.push(generate(random(Number(seed) + i), corpus, Number(max)))
writeFileSync(out, JSON.stringify(docs))
