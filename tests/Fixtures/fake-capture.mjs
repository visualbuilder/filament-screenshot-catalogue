import { writeFileSync } from 'node:fs';
let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const m = JSON.parse(raw);
writeFileSync(process.env.FAKE_CAPTURE_MANIFEST, raw);
const path = `${m.outDir}/${m.pages[0].slug}/desktop-light.png`;
console.log(JSON.stringify({ captured: [{ slug: m.pages[0].slug, viewport: 'desktop', mode: 'light', path, axe: m.axe ? path.replace(/\.png$/, '.axe.json') : null }] }));
