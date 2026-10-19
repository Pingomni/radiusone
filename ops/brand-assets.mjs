// Regenerates public/favicon.*, public/icon-*.png, public/apple-touch-icon.png and public/brand/*.
// Needs (outside the app): npm i @resvg/resvg-js@2.6.2 opentype.js@1.3.4 @fontsource/inter@5.0.18; writes to ./out.
// Builds the RadiusWeb mark, wordmark lockups, favicons and app icons into ./out.
import fs from 'node:fs';
import opentype from 'opentype.js';
import { Resvg } from '@resvg/resvg-js';

const out = new URL('./out/', import.meta.url).pathname;
fs.mkdirSync(out, { recursive: true });

const BLUE_A = '#2f6bf0';
const BLUE_B = '#4338ca';
const TEAL = '#5eead4';
const INK = '#0f172a';

// The mark: a hub (the RADIUS server) with three arcs radiating to the top right (its reach).
const defs = (id) => `<linearGradient id="${id}" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="${BLUE_A}"/><stop offset="1" stop-color="${BLUE_B}"/></linearGradient>`;
function markShapes({ stroke = 6.5, node = 5.5 } = {}) {
    // Monogram R: stem and round bowl in white; the leg runs out as a network link to a teal node.
    const w = `stroke="#ffffff" stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round" fill="none"`;
    return `<path d="M22 47V17h10.5a8.75 8.75 0 0 1 0 17.5H22" ${w}/>`
        + `<path d="M32 34.5L40.5 43" ${w}/>`
        + `<circle cx="44.5" cy="47" r="${node}" fill="${TEAL}"/>`;
}
function mark({ size = 64, radius = 15, bleed = false, small = false, id = 'rwg' } = {}) {
    const shapes = small ? markShapes({ stroke: 8, node: 6.5 }) : markShapes();
    const bg = bleed ? `<rect width="64" height="64" fill="url(#${id})"/>` : `<rect width="64" height="64" rx="${radius}" fill="url(#${id})"/>`;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 64 64"><defs>${defs(id)}</defs>${bg}${shapes}</svg>`;
}
// Maskable/full-bleed icons keep the artwork inside the central safe zone.
function padded({ size, pad }) {
    const inner = 64 - pad * 2;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 64 64"><defs>${defs('rwp')}</defs><rect width="64" height="64" fill="url(#rwp)"/><g transform="translate(${pad} ${pad}) scale(${inner / 64})">${markShapes()}</g></svg>`;
}

// Wordmark: Inter Bold converted to outlines, "Radius" in ink, "Web" in brand blue.
const font = opentype.loadSync(new URL('./node_modules/@fontsource/inter/files/inter-latin-700-normal.woff', import.meta.url).pathname);
function word(text, x, baseline, sizePx, tracking = -0.02) {
    let d = '';
    let cursor = x;
    const glyphs = font.stringToGlyphs(text);
    glyphs.forEach((g, i) => {
        d += g.getPath(cursor, baseline, sizePx).toPathData(2);
        const kern = i < glyphs.length - 1 ? font.getKerningValue(g, glyphs[i + 1]) : 0;
        cursor += (g.advanceWidth + kern) * (sizePx / font.unitsPerEm) + tracking * sizePx;
    });
    return { d, end: cursor };
}
function lockup({ dark = false } = {}) {
    const size = 64, gap = 18, fs_ = 40, baseline = 46;
    const a = word('Radius', size + gap, baseline, fs_);
    const b = word('Web', a.end, baseline, fs_);
    const width = Math.ceil(b.end + 2);
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width * 4}" height="${size * 4}" viewBox="0 0 ${width} ${size}" role="img" aria-label="RadiusWeb">
<defs>${defs('rwl')}</defs><rect width="64" height="64" rx="15" fill="url(#rwl)"/>${markShapes()}
<path d="${a.d}" fill="${dark ? '#ffffff' : INK}"/><path d="${b.d}" fill="${dark ? '#7fb0ff' : '#1f5fd6'}"/></svg>`;
}

const png = (svg, width) => new Resvg(svg, { fitTo: { mode: 'width', value: width } }).render().asPng();
const write = (name, data) => { fs.writeFileSync(out + name, data); };

// Favicon SVG: full mark; browsers scale it.
write('favicon.svg', mark({ size: 64 }).replace('<svg ', '<svg role="img" aria-label="RadiusWeb" '));
write('logo-mark.svg', mark({ size: 64, id: 'rwm' }));
write('logo.svg', lockup());
write('logo-dark.svg', lockup({ dark: true }));
write('logo.png', png(lockup(), 1200));
write('logo-dark.png', png(lockup({ dark: true }), 1200));
write('apple-touch-icon.png', png(padded({ size: 180, pad: 8 }), 180));
write('icon-192.png', png(mark({ size: 192, radius: 15 }), 192));
write('icon-512.png', png(mark({ size: 512, radius: 15 }), 512));
write('icon-maskable-512.png', png(padded({ size: 512, pad: 12 }), 512));

// favicon.ico with PNG entries; 16 px uses the simplified two-arc mark so it stays crisp.
const entries = [16, 32, 48].map((s) => ({ s, data: png(mark({ size: s, small: s < 32, radius: s < 32 ? 13 : 15 }), s) }));
const header = Buffer.alloc(6 + entries.length * 16);
header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(entries.length, 4);
let offset = header.length;
entries.forEach(({ s, data }, i) => {
    const o = 6 + i * 16;
    header.writeUInt8(s, o); header.writeUInt8(s, o + 1); header.writeUInt8(0, o + 2); header.writeUInt8(0, o + 3);
    header.writeUInt16LE(1, o + 4); header.writeUInt16LE(32, o + 6);
    header.writeUInt32LE(data.length, o + 8); header.writeUInt32LE(offset, o + 12);
    offset += data.length;
});
write('favicon.ico', Buffer.concat([header, ...entries.map((e) => e.data)]));

// Preview sheet for review only.
const sheet = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="620" viewBox="0 0 1200 620">
<rect width="1200" height="310" fill="#f3f4f6"/><rect y="310" width="1200" height="310" fill="#151b24"/>
<image href="data:image/svg+xml;base64,${Buffer.from(lockup()).toString('base64')}" x="60" y="40" width="${lockup().match(/viewBox="0 0 (\d+)/)[1] * 2}" height="128"/>
<image href="data:image/svg+xml;base64,${Buffer.from(lockup({ dark: true })).toString('base64')}" x="60" y="350" width="${lockup().match(/viewBox="0 0 (\d+)/)[1] * 2}" height="128"/>
${[16, 32, 48, 64, 128].map((s, i) => `<image href="data:image/svg+xml;base64,${Buffer.from(mark({ size: s, small: s < 32, radius: s < 32 ? 13 : 15, id: 'p' + i })).toString('base64')}" x="${60 + [0, 40, 100, 180, 280][i]}" y="${230 - s / 2}" width="${s}" height="${s}"/>`).join('')}
${[16, 32, 48, 64, 128].map((s, i) => `<image href="data:image/svg+xml;base64,${Buffer.from(mark({ size: s, small: s < 32, radius: s < 32 ? 13 : 15, id: 'q' + i })).toString('base64')}" x="${60 + [0, 40, 100, 180, 280][i]}" y="${540 - s / 2}" width="${s}" height="${s}"/>`).join('')}
</svg>`;
write('logo-invoice.png', new Resvg(lockup(), { fitTo: { mode: 'width', value: 600 }, background: '#ffffff' }).render().asPng());
write('preview.png', png(sheet, 1200));
console.log(fs.readdirSync(out).map((f) => `${f} ${fs.statSync(out + f).size}`).join('\n'));
