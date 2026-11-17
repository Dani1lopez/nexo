#!/usr/bin/env node
// Mide el peso real de los recursos propios compilados y falla si se pasa del presupuesto.
// Uso: node scripts/check-weight.mjs
// Requiere `npm run build` antes: lee public/build/manifest.json.

import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const buildDir = join(root, 'public', 'build');
const manifestPath = join(buildDir, 'manifest.json');

// Presupuesto del issue #26. Lo que se descarga el usuario manda (gzip); el tamaño sin
// comprimir se vigila como segundo tope para que el CSS no crezca sin control.
// Los límites se pueden bajar por entorno para comprobar que la comprobación falla.
const kbToBytes = (name, fallbackKb) => {
  const raw = process.env[name];
  if (!raw) return fallbackKb * 1024;
  const value = Number(raw);
  return Number.isFinite(value) && value > 0 ? Math.round(value * 1024) : fallbackKb * 1024;
};

const BUDGET = {
  css: { gzip: kbToBytes('WEIGHT_CSS_GZIP_KB', 12), raw: kbToBytes('WEIGHT_CSS_RAW_KB', 48) },
  js: { gzip: kbToBytes('WEIGHT_JS_GZIP_KB', 12), raw: kbToBytes('WEIGHT_JS_RAW_KB', 16) },
};

if (!existsSync(manifestPath)) {
  console.error('Falta public/build/manifest.json. Ejecuta `npm run build` antes de medir.');
  process.exit(2);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

const files = new Map();
for (const entry of Object.values(manifest)) {
  for (const key of ['file', 'css', 'assets']) {
    const value = entry[key];
    if (!value) continue;
    for (const rel of Array.isArray(value) ? value : [value]) {
      if (/\.(css|js)$/.test(rel)) files.set(rel, rel);
    }
  }
}

if (files.size === 0) {
  console.error('El manifiesto no declara recursos CSS o JS.');
  process.exit(2);
}

const rows = [...files.keys()].sort().map((rel) => {
  const bytes = statSync(join(buildDir, rel)).size;
  const gzip = gzipSync(readFileSync(join(buildDir, rel))).length;
  const kind = rel.endsWith('.css') ? 'css' : 'js';
  return { rel, kind, bytes, gzip };
});

const totals = rows.reduce(
  (acc, r) => {
    acc[r.kind].bytes += r.bytes;
    acc[r.kind].gzip += r.gzip;
    return acc;
  },
  { css: { bytes: 0, gzip: 0 }, js: { bytes: 0, gzip: 0 } },
);

const kb = (n) => `${(n / 1024).toFixed(2)} KB`;

for (const r of rows) {
  console.log(`  ${r.rel.padEnd(28)} ${kb(r.bytes).padStart(10)} sin comprimir   ${kb(r.gzip).padStart(9)} gzip`);
}

// Fuentes: informativas, no presupuestadas. El navegador solo descarga el subconjunto que usa
// gracias a unicode-range, así que la suma no es lo que recibe una visita concreta.
const fontDir = join(buildDir, 'assets');
if (existsSync(fontDir)) {
  const fonts = readdirSync(fontDir).filter((n) => n.endsWith('.woff2'));
  const bytes = fonts.reduce((sum, n) => sum + statSync(join(fontDir, n)).size, 0);
  if (fonts.length > 0) {
    console.log(`  ${'fuentes woff2'.padEnd(28)} ${kb(bytes).padStart(10)} en total, ${fonts.length} subconjuntos (informático)`);
  }
}

const failures = [];
for (const kind of ['css', 'js']) {
  const gzipUsed = totals[kind].gzip;
  const gzipLimit = BUDGET[kind].gzip;
  const rawUsed = totals[kind].bytes;
  const rawLimit = BUDGET[kind].raw;
  console.log(
    `${kind.toUpperCase()} propio: ${kb(gzipUsed)} gzip de ${kb(gzipLimit)} (${((gzipUsed / gzipLimit) * 100).toFixed(1)} %) · ${kb(rawUsed)} sin comprimir de ${kb(rawLimit)}`,
  );
  if (gzipUsed > gzipLimit) {
    failures.push(`${kind.toUpperCase()} comprimido ${kb(gzipUsed)} supera ${kb(gzipLimit)}`);
  }
  if (rawUsed > rawLimit) {
    failures.push(`${kind.toUpperCase()} sin comprimir ${kb(rawUsed)} supera ${kb(rawLimit)}`);
  }
}

if (failures.length > 0) {
  console.error('\nPresupuesto incumplido:');
  for (const f of failures) console.error(`  - ${f}`);
  process.exit(1);
}

console.log('\nDentro del presupuesto.');
