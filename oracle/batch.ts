// Black-box pinned oracle: node batch.ts IN.json OUT.jsonl.
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
const root = resolve(process.env.CARVE_JS_DIR ?? '../carve-js')
const { parse, render } = await import(pathToFileURL(resolve(root, 'src/index.ts')).href)
const inputs: string[] = JSON.parse(readFileSync(process.argv[2]!, 'utf8'))
const out: string[] = []
for (const src of inputs) {
  const rec: Record<string, unknown> = {}
  try { rec.html = render(src) } catch (e) { rec.htmlError = String(e) }
  try { rec.tree = parse(src) } catch (e) { rec.treeError = String(e) }
  out.push(JSON.stringify(rec))
}
writeFileSync(process.argv[3]!, out.join('\n') + '\n')
