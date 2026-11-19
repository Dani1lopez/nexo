// Verifica el respaldo de contraste del texto pequeño de la portada.
//
// La escena 3D puede poner cualquier píxel detrás del texto: el peor caso es blanco puro. El
// texto pequeño (por debajo del umbral de texto grande de WCAG) necesita 4.5:1, así que todo
// bloque de texto pequeño lleva un velo opaco lo suficiente (`--lp-veil`) o un fondo opaco
// (`--stage-deep`). Este archivo lee los tokens reales de `resources/css/*.css`, compone el
// peor caso y falla si alguien baja el alfa por debajo del mínimo calculado o deja un bloque
// de texto pequeño sin respaldo.
//
// Se ejecuta con `node --test scripts/test-landing-contrast.mjs`.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(fileURLToPath(import.meta.url), '..', '..');
const appCss = readFileSync(join(root, 'resources', 'css', 'app.css'), 'utf8');
const landingCss = readFileSync(join(root, 'resources', 'css', 'landing.css'), 'utf8');

/** El valor oscuro de un token declarado con light-dark(). */
function darkToken(css, name) {
  const match = css.match(new RegExp(`--${name}:\\s*light-dark\\(\\s*(#[0-9a-fA-F]{6})\\s*,\\s*(#[0-9a-fA-F]{6})`));
  assert.ok(match, `no se encontró el token --${name} con light-dark(claro, oscuro)`);

  return match[2];
}

/** Cuerpo de la primera regla que contiene ese selector (puede compartir lista con otros). */
function block(css, selector) {
  const index = css.indexOf(selector);
  assert.notEqual(index, -1, `no se encontró la regla «${selector}»`);

  const open = css.indexOf('{', index);

  return css.slice(index, css.indexOf('}', open) + 1);
}

const hexToRgb = (hex) => [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16) / 255);

const toLinear = (channel) => (channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);

const luminance = (rgb) => {
  const [r, g, b] = rgb.map(toLinear);

  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

const contrast = (a, b) => {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

  return (light + 0.05) / (dark + 0.05);
};

/** Mezcla en sRGB, como hace color-mix(): primero el color base con su peso. */
const mix = (base, other, weight) => base.map((channel, index) => channel * weight + other[index] * (1 - weight));

const WHITE = [1, 1, 1];

/** --stage-void = --color-canvas oscuro mezclado con negro. */
const stageVoidWeight = Number(
  landingCss.match(/--stage-void:\s*color-mix\(in srgb, var\(--color-canvas\)\s+(\d+)%,\s*#000\)/)[1],
) / 100;
const stageVoid = mix(hexToRgb(darkToken(appCss, 'color-canvas')), [0, 0, 0], stageVoidWeight);

/** Alfa declarado en --lp-veil (0 si todavía no existe: la prueba falla con su mensaje). */
const veilAlpha = Number(
  landingCss.match(/--lp-veil:\s*color-mix\(in srgb, var\(--stage-void\)\s+(\d+)%,\s*transparent\)/)?.[1] ?? 0,
) / 100;

const muted = hexToRgb(darkToken(appCss, 'color-muted'));
const ink = hexToRgb(darkToken(appCss, 'color-ink'));

/** El texto más el velo, sobre la escena más brillante posible. */
const worstCase = (text, alpha) => contrast(text, mix(stageVoid, WHITE, alpha));

/** El alfa mínimo que hace que `text` llegue a 4.5:1 sobre blanco puro. */
function minimumAlpha(text, target = 4.5) {
  for (let alpha = 0; alpha <= 1; alpha += 0.005) {
    if (worstCase(text, alpha) >= target) return alpha;
  }

  return 1;
}

test('el velo del texto pequeño supera el peor caso: blanco puro detrás', () => {
  const required = minimumAlpha(muted);

  assert.ok(
    veilAlpha >= required,
    `--lp-veil está al ${(veilAlpha * 100).toFixed(0)} % y el mínimo para --color-muted es ${(required * 100).toFixed(1)} %`,
  );

  const measured = worstCase(muted, veilAlpha);
  assert.ok(measured >= 4.5, `el texto secundario queda en ${measured.toFixed(2)}:1, por debajo de 4.5:1`);
  assert.ok(worstCase(ink, veilAlpha) >= 4.5, 'el texto principal también cumple');
});

test('ningún bloque de texto pequeño queda sin respaldo propio', () => {
  // Cada uno de estos bloques pone texto pequeño sobre la escena, no sobre una sección opaca.
  const blocks = [
    '.lp-open-main .lp-label',
    '.lp-chapter .lp-label',
    '.lp-lead',
    '.lp-note',
    '.lp-open-side',
    '.lp-body',
    '.lp-hud',
  ];

  for (const selector of blocks) {
    const body = block(landingCss, selector);
    assert.ok(
      body.includes('var(--lp-veil)'),
      `«${selector}» no usa var(--lp-veil): su texto puede quedar sobre un píxel claro de la escena`,
    );
  }
});

test('la barra no se apoya en un tramo demasiado transparente', () => {
  const topbar = block(landingCss, '.landing-page .topbar');
  const stops = [...topbar.matchAll(/var\(--stage-void\)\s+(\d+)%/g)].map((match) => Number(match[1]) / 100);

  assert.ok(stops.length > 0, 'la barra declara su propio fondo');

  // El texto de la barra (marca y estado) es pequeño: se exige el mínimo del peor caso.
  const required = minimumAlpha(muted);
  for (const stop of stops) {
    assert.ok(stop >= required, `la barra tiene un tramo al ${(stop * 100).toFixed(0)} %, por debajo de ${(required * 100).toFixed(1)} %`);
  }
});

test('los velos declarados dejan el texto grande por encima de 3:1', () => {
  // Texto grande (titulares): 3:1 con el mismo peor caso detrás.
  assert.ok(worstCase(ink, 0.5) >= 3, 'un velo del 50 % ya sostiene un titular');
});
