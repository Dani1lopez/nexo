// Verifica el presupuesto de peso por página de `scripts/check-weight.mjs`.
//
// La portada carga Three.js con su propia entrada de Vite; el resto de la aplicación
// conserva el presupuesto global del issue #26. Este archivo construye manifiestos de
// Vite falsos en un directorio temporal del sistema y comprueba las cuatro reglas:
// cada página mide lo que declara, la portada tiene su propio límite, ninguna otra
// página alcanza Three.js y las variables de entorno siguen bajando los límites.
//
// Se ejecuta con `node --test scripts/test-check-weight.mjs`.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { copyFileSync, mkdtempSync, mkdirSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { evaluateWeight, MISSING_MANIFEST_MESSAGE } from './check-weight.mjs';

/** Contenido incompresible de un tamaño exacto, para que gzip no lo reduzca. */
const incompressible = (bytes) => randomBytes(bytes).toString('base64');

/**
 * Escribe un build de mentira y devuelve la ruta que recibe `evaluateWeight`.
 *
 * @param {Record<string, { gzipBytes: number, content?: string }>} chunks
 * @param {object} manifest
 */
function fakeBuild(chunks, manifest) {
  const directory = mkdtempSync(join(tmpdir(), 'nexo-weight-'));
  mkdirSync(join(directory, 'assets'), { recursive: true });
  writeFileSync(join(directory, 'manifest.json'), JSON.stringify(manifest));

  for (const [relative, chunk] of Object.entries(chunks)) {
    writeFileSync(path(directory, relative), chunk.content ?? incompressible(chunk.gzipBytes));
  }

  return directory;
}

const path = (directory, relative) => join(directory, relative);

/** Build mínimo válido: una entrada de aplicación y una portada con su trozo de Three.js. */
function defaultBuild({ landingBytes = 8000, threeBytes = 60000, appCssBytes = 1000 } = {}) {
  const chunks = {
    'assets/app-1.css': { gzipBytes: appCssBytes },
    'assets/theme-1.js': { gzipBytes: 400 },
    'assets/landing-1.js': { gzipBytes: landingBytes },
    'assets/landing-1.css': { gzipBytes: 2000 },
    // El trozo de Three.js se reconoce por una cadena que solo está en la librería.
    'assets/three-1.js': { gzipBytes: threeBytes, content: `${incompressible(threeBytes)} THREE.WebGLRenderer` },
  };

  const manifest = {
    'resources/css/app.css': { file: 'assets/app-1.css', src: 'resources/css/app.css', isEntry: true },
    'resources/js/theme.js': { file: 'assets/theme-1.js', src: 'resources/js/theme.js', isEntry: true },
    'resources/js/landing.js': {
      file: 'assets/landing-1.js',
      src: 'resources/js/landing.js',
      isEntry: true,
      dynamicImports: ['_three-1.js'],
      css: ['assets/landing-1.css'],
    },
    'resources/css/landing.css': { file: 'assets/landing-1.css', src: 'resources/css/landing.css', isEntry: true },
    // Trozo cargado en diferido: Vite lo declara como una entrada más del manifiesto.
    '_three-1.js': { file: 'assets/three-1.js', src: '_three-1.js' },
  };

  return { directory: fakeBuild(chunks, manifest), manifest };
}

const scope = (result, key) => result.scopes.find((item) => item.key === key);

test('cada página mide sus propios recursos y la portada incluye Three.js', () => {
  const build = defaultBuild();

  try {
    const result = evaluateWeight({ buildDir: build.directory, env: {} });

    assert.deepEqual(result.failures, []);

    const app = scope(result, 'app');
    const landing = scope(result, 'landing');

    assert.ok(app, 'el resultado declara el grupo del resto de la aplicación');
    assert.ok(landing, 'el resultado declara el grupo de la portada');

    // El resto de la aplicación nunca arrastra el trozo de la portada ni Three.js.
    assert.deepEqual(app.files.sort(), ['assets/app-1.css', 'assets/theme-1.js'].sort());
    assert.deepEqual(
      landing.files.sort(),
      ['assets/landing-1.css', 'assets/landing-1.js', 'assets/three-1.js'].sort(),
    );

    assert.deepEqual(landing.threeFiles, ['assets/three-1.js']);
    assert.deepEqual(app.threeFiles, []);
    assert.deepEqual(result.threeFiles, ['assets/three-1.js'], 'Three.js se mide una sola vez, en la portada');
  } finally {
    rmSync(build.directory, { recursive: true, force: true });
  }
});

test('la portada incumple solo su propio presupuesto', () => {
  const build = defaultBuild({ landingBytes: 400_000 });

  try {
    // El presupuesto de la portada se pasa por el propio evaluador para no fijar aquí el número real.
    const result = evaluateWeight({
      buildDir: build.directory,
      env: {},
      budgetKb: { landing: { jsGzip: 100, jsRaw: 200, cssGzip: 12, cssRaw: 48 } },
    });

    assert.deepEqual(scope(result, 'app').failures, [], 'el resto de la aplicación sigue dentro del presupuesto');
    assert.equal(scope(result, 'landing').failures.length > 0, true, 'la portada se pasa de su límite');
    assert.match(result.failures.join('\n'), /portada/i);
  } finally {
    rmSync(build.directory, { recursive: true, force: true });
  }
});

test('falla si otra página carga Three.js o el recurso de la portada', () => {
  const chunks = {
    'assets/app-1.js': { gzipBytes: 900 },
    'assets/app-1.css': { gzipBytes: 1000 },
    'assets/theme-1.js': { gzipBytes: 400 },
    'assets/landing-1.js': { gzipBytes: 3000 },
    'assets/landing-1.css': { gzipBytes: 1000 },
    'assets/three-1.js': { gzipBytes: 1000, content: `${incompressible(1000)} THREE.WebGLRenderer` },
  };

  const leaked = {
    'resources/css/app.css': { file: 'assets/app-1.css', src: 'resources/css/app.css', isEntry: true },
    'resources/js/theme.js': {
      file: 'assets/app-1.js',
      src: 'resources/js/theme.js',
      isEntry: true,
      // La aplicación no debe importar la entrada de la portada ni su trozo.
      imports: ['_three-1.js'],
    },
    'resources/js/landing.js': {
      file: 'assets/landing-1.js',
      src: 'resources/js/landing.js',
      isEntry: true,
      dynamicImports: ['_three-1.js'],
      css: ['assets/landing-1.css'],
    },
    'resources/css/landing.css': { file: 'assets/landing-1.css', src: 'resources/css/landing.css', isEntry: true },
    '_three-1.js': { file: 'assets/three-1.js', src: '_three-1.js' },
  };

  const directory = fakeBuild(chunks, leaked);

  try {
    const result = evaluateWeight({ buildDir: directory, env: {} });

    assert.equal(result.failures.length > 0, true, 'el informe falla en vez de aprobar la fuga');
    assert.match(result.failures.join('\n'), /Three\.js|portada/i);
    assert.deepEqual(scope(result, 'app').threeFiles, ['assets/three-1.js'], 'el informe nombra la fuga');
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('las variables de entorno siguen bajando los límites de cada página', () => {
  const build = defaultBuild();

  try {
    const strictApp = evaluateWeight({ buildDir: build.directory, env: { WEIGHT_JS_GZIP_KB: '0.001' } });
    assert.equal(strictApp.failures.length > 0, true, 'WEIGHT_JS_GZIP_KB sigue mandando en el resto de páginas');

    const strictLanding = evaluateWeight({ buildDir: build.directory, env: { WEIGHT_LANDING_JS_GZIP_KB: '0.001' } });
    assert.equal(strictLanding.failures.length > 0, true, 'la portada acepta su propio límite por entorno');
    assert.deepEqual(scope(strictLanding, 'app').failures, [], 'el límite de la portada no toca al resto');
  } finally {
    rmSync(build.directory, { recursive: true, force: true });
  }
});

test('sin manifiesto el informe pide compilar en vez de aprobar', () => {
  const directory = mkdtempSync(join(tmpdir(), 'nexo-weight-'));

  try {
    assert.throws(() => evaluateWeight({ buildDir: directory, env: {} }), (error) => {
      assert.equal(error.message, MISSING_MANIFEST_MESSAGE);

      return true;
    });
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

/** Ejecuta el script real como CLI, con su propio build y su propio entorno. */
function runCli({ env, appCssBytes = 8000 } = {}) {
  const { directory } = defaultBuild({ appCssBytes });
  const root = mkdtempSync(join(tmpdir(), 'nexo-weight-cli-'));

  mkdirSync(join(root, 'scripts'));
  mkdirSync(join(root, 'public'));
  renameSync(directory, join(root, 'public', 'build'));

  const script = join(root, 'scripts', 'check-weight.mjs');
  copyFileSync(new URL('./check-weight.mjs', import.meta.url), script);

  const result = spawnSync(process.execPath, [script], {
    env: { ...process.env, ...env },
    encoding: 'utf8',
  });

  return { result, cleanup: () => rmSync(root, { recursive: true, force: true }) };
}

test('el CLI aplica los límites de su propio entorno: aplicación', () => {
  const { result, cleanup } = runCli({ env: { WEIGHT_CSS_GZIP_KB: '1' } });

  try {
    assert.ifError(result.error);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stdout + result.stderr, /Resto de la aplicación.*CSS comprimido/);
  } finally {
    cleanup();
  }
});

test('el CLI aplica los límites de su propio entorno: portada', () => {
  const { result, cleanup } = runCli({ env: { WEIGHT_LANDING_JS_GZIP_KB: '0.001' } });

  try {
    assert.ifError(result.error);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stdout + result.stderr, /Portada.*JS comprimido/);
  } finally {
    cleanup();
  }
});

test('una entrada no declarada no puede llevar la portada a otra página', () => {
  const { directory, manifest } = defaultBuild();

  try {
    const file = 'assets/new-page.js';
    manifest['resources/js/new-page.js'] = { isEntry: true, file, imports: ['resources/js/landing.js'] };
    writeFileSync(join(directory, file), 'export {};');
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify(manifest));

    const result = evaluateWeight({ buildDir: directory, env: {} });

    assert.notEqual(result.failures.length, 0, 'el informe falla en vez de ignorar la página nueva');
    assert.match(result.failures.join('\n'), /new-page|portada/i);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('un archivo que el manifiesto declara y no existe falla el informe', () => {
  const { directory, manifest } = defaultBuild();

  try {
    manifest['resources/js/theme.js'].file = 'assets/not-built.js';
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify(manifest));

    const result = evaluateWeight({ buildDir: directory, env: {} });

    assert.notEqual(result.failures.length, 0, 'un archivo ausente no se mide como 0 bytes');
    assert.match(result.failures.join('\n'), /not-built|no existe|falta/i);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('una importación que apunta a un trozo inexistente falla el informe', () => {
  const { directory, manifest } = defaultBuild();

  try {
    manifest['resources/js/theme.js'].imports = ['_missing-chunk.js'];
    writeFileSync(join(directory, 'manifest.json'), JSON.stringify(manifest));

    const result = evaluateWeight({ buildDir: directory, env: {} });

    assert.notEqual(result.failures.length, 0, 'un salto roto no se ignora');
    assert.match(result.failures.join('\n'), /missing-chunk|importación/i);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
