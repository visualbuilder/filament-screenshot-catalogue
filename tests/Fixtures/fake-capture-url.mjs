import { writeFileSync } from 'node:fs';
let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const m = JSON.parse(raw);
writeFileSync(process.env.FAKE_CAPTURE_MANIFEST, raw);
for (const shot of m.shots) {
    writeFileSync(shot.output, '');
    if (m.axe) {
        const axe = /\.png$/i.test(shot.output) ? shot.output.replace(/\.png$/i, '.axe.json') : shot.output + '.axe.json';
        writeFileSync(axe, '{}');
    }
}
