#!/usr/bin/env node
// Mide el peso real de los recursos propios compilados y falla si una página se pasa de su presupuesto.
// Uso: node scripts/check-weight.mjs
// Requiere `npm run build` antes: lee public/build/manifest.json.
//
// Presupuesto por página (issue #60):
// - El resto de la aplicación conserva el presupuesto global del issue #26 (12 KB gzip de
//   JavaScript y 12 KB gzip de CSS, con sus topes sin comprimir).
// - La portada tiene su propia entrada de Vite, que incluye Three.js, y su propio presupuesto,
//   medido sobre la compilación real con algo de holgura. Se imprime siempre.
// - Ninguna otra página puede alcanzar el recurso de la portada ni Three.js: si el cierre de
//   importaciones de su entrada toca un archivo de la portada, el informe falla.
//
// Los límites se pueden bajar por entorno para comprobar que la comprobación falla de verdad:
// WEIGHT_CSS_GZIP_KB, WEIGHT_CSS_RAW_KB, WEIGHT_JS_GZIP_KB, WEIGHT_JS_RAW_KB para el resto de
// la aplicación; WEIGHT_LANDING_{CSS,JS}_{GZIP,RAW}_KB para la portada.

import { readFileSync, readdirSync, existsSync, realpathSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const MISSING_MANIFEST_MESSAGE = 'Falta public/build/manifest.json. Ejecuta `npm run build` antes de medir.';

/**
 * Marca que solo aparece dentro de Three.js: los mensajes de la librería llevan el prefijo
 * 'THREE.'. El propio código de la portada nunca lo escribe, así que no se confunde con ella.
 */
const THREE_SIGNATURE = /THREE\.[A-Z]/;

/**
 * Páginas presupuestadas. Cada una declara sus entradas de Vite; el cierre de importaciones
 * (estáticas y dinámicas) se mide entero, porque eso es lo que llega a descargar una visita.
 */
export const PAGE_SCOPES = [
    {
        key: 'app',
        label: 'Resto de la aplicación',
        entries: ['resources/css/app.css', 'resources/js/theme.js'],
    },
    {
        key: 'landing',
        label: 'Portada (landing 3D)',
        entries: ['resources/css/landing.css', 'resources/js/landing.js'],
    },
];

/** Presupuesto en KB. El de la portada se fija desde la compilación real (ver el informe). */
export const DEFAULT_BUDGET_KB = {
    app: { cssGzip: 12, cssRaw: 48, jsGzip: 12, jsRaw: 16 },
    landing: { cssGzip: 5, cssRaw: 20, jsGzip: 205, jsRaw: 810 },
};

/** Variable de entorno que puede bajar cada límite, por página. */
export const BUDGET_ENV = {
    app: {
        cssGzip: 'WEIGHT_CSS_GZIP_KB',
        cssRaw: 'WEIGHT_CSS_RAW_KB',
        jsGzip: 'WEIGHT_JS_GZIP_KB',
        jsRaw: 'WEIGHT_JS_RAW_KB',
    },
    landing: {
        cssGzip: 'WEIGHT_LANDING_CSS_GZIP_KB',
        cssRaw: 'WEIGHT_LANDING_CSS_RAW_KB',
        jsGzip: 'WEIGHT_LANDING_JS_GZIP_KB',
        jsRaw: 'WEIGHT_LANDING_JS_RAW_KB',
    },
};

const kbToBytes = (value) => Math.round(value * 1024);

const kindOf = (relative) => (relative.endsWith('.css') ? 'css' : 'js');

/** Un límite por entorno: si la variable falta o no es un número positivo, manda el valor por defecto. */
const limit = (env, variable, fallbackKb) => {
    const raw = env?.[variable];
    if (!raw) return kbToBytes(fallbackKb);
    const value = Number(raw);

    return Number.isFinite(value) && value > 0 ? kbToBytes(value) : kbToBytes(fallbackKb);
};

/**
 * Archivos que descarga una entrada: ella misma, sus hojas de estilo, sus recursos y su
 * cierre completo de importaciones estáticas y dinámicas. Los saltos que el manifiesto no
 * declara no se ignoran: se devuelven como `missing` para que el informe falle.
 *
 * @returns {{ files: Set<string>, missing: Set<string> }}
 */
function closure(manifest, entryKey) {
    const files = new Set();
    const missing = new Set();
    const seen = new Set();
    const queue = [entryKey];

    while (queue.length > 0) {
        const key = queue.shift();
        if (key === undefined || seen.has(key)) continue;
        seen.add(key);

        const entry = manifest[key];
        if (!entry) {
            missing.add(key);
            continue;
        }

        for (const field of ['file', 'css', 'assets']) {
            const value = entry[field];
            if (!value) continue;
            for (const relative of Array.isArray(value) ? value : [value]) {
                if (/\.(css|js)$/.test(relative)) files.add(relative);
            }
        }

        // `imports` son trozos compartidos y `dynamicImports` el resto que se pide después
        // (aquí, Three.js): los dos cuentan para el peso de la página.
        for (const field of ['imports', 'dynamicImports']) {
            for (const imported of entry[field] ?? []) queue.push(imported);
        }
    }

    return { files, missing };
}

const kb = (n) => `${(n / 1024).toFixed(2)} KB`;

/**
 * Mide un build y devuelve el informe. No imprime ni termina el proceso: eso lo hace `main`.
 *
 * @param {{ buildDir?: string, env?: Record<string, string | undefined>, budgetKb?: Record<string, object>, scopes?: typeof PAGE_SCOPES }} options
 */
export function evaluateWeight({ buildDir, env = {}, budgetKb = DEFAULT_BUDGET_KB, scopes = PAGE_SCOPES } = {}) {
    const directory = buildDir ?? join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'build');
    const manifestPath = join(directory, 'manifest.json');

    if (!existsSync(manifestPath)) {
        throw new Error(MISSING_MANIFEST_MESSAGE);
    }

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    const failures = [];
    const reports = [];
    const declared = new Set(scopes.flatMap((scope) => scope.entries));
    const landingScope = scopes.find((scope) => scope.key === 'landing');
    const landingFiles = new Set();

    if (landingScope) {
        for (const entryKey of landingScope.entries) {
            const { files, missing } = closure(manifest, entryKey);
            for (const relative of files) landingFiles.add(relative);
            for (const key of missing) {
                failures.push(`El manifiesto importa «${key}», que no existe: revisa la compilación.`);
            }
        }
    }

    // Cualquier entrada que no esté declarada es una página más de la aplicación: entra en su
    // presupuesto y, si toca el recurso de la portada, falla. Así una vista nueva no puede
    // colarse cargando Three.js sin que nadie lo revise.
    const extraEntries = Object.keys(manifest).filter(
        (key) => manifest[key]?.isEntry === true && !declared.has(key),
    );
    const extras = new Map();
    for (const key of extraEntries) {
        const { files, missing } = closure(manifest, key);
        for (const absent of missing) {
            failures.push(`La entrada «${key}» importa «${absent}», que el manifiesto no declara.`);
        }
        const leaked = [...files].filter((file) => landingFiles.has(file));
        if (leaked.length > 0) {
            failures.push(
                `La entrada «${key}» carga recursos de la portada (${leaked.join(', ')}), y la portada es la única página que puede cargar Three.js`,
            );
        }
        extras.set(key, files);
    }

    for (const scope of scopes) {
        const files = new Set();
        for (const entryKey of scope.entries) {
            if (!manifest[entryKey]) {
                failures.push(`${scope.label}: el manifiesto no declara «${entryKey}»`);
                continue;
            }
            const { files: closureFiles, missing } = closure(manifest, entryKey);
            for (const relative of closureFiles) files.add(relative);
            for (const key of missing) {
                failures.push(`${scope.label}: importa «${key}», que el manifiesto no declara`);
            }
        }

        // Las entradas no declaradas también son páginas de la aplicación y se miden aquí.
        if (scope.key !== 'landing') {
            for (const extra of extras.values()) {
                for (const relative of extra) files.add(relative);
            }
        }

        const rows = [...files].sort().map((relative) => {
            const path = join(directory, relative);
            const present = existsSync(path);
            if (!present) {
                failures.push(`${scope.label}: el manifiesto declara «${relative}» y no existe en el build`);
            }
            const bytes = present ? statSync(path).size : 0;
            const gzip = present ? gzipSync(readFileSync(path)).length : 0;
            const content = present && /\.js$/.test(relative) ? readFileSync(path, 'utf8') : '';

            return {
                rel: relative,
                kind: kindOf(relative),
                bytes,
                gzip,
                three: THREE_SIGNATURE.test(content),
            };
        });

        const totals = { css: { bytes: 0, gzip: 0 }, js: { bytes: 0, gzip: 0 } };
        for (const row of rows) {
            totals[row.kind].bytes += row.bytes;
            totals[row.kind].gzip += row.gzip;
        }

        const budget = { ...(DEFAULT_BUDGET_KB[scope.key] ?? DEFAULT_BUDGET_KB.app), ...(budgetKb[scope.key] ?? {}) };
        const keys = BUDGET_ENV[scope.key] ?? BUDGET_ENV.app;
        const limits = {
            css: {
                gzip: limit(env, keys.cssGzip, budget.cssGzip),
                raw: limit(env, keys.cssRaw, budget.cssRaw),
            },
            js: {
                gzip: limit(env, keys.jsGzip, budget.jsGzip),
                raw: limit(env, keys.jsRaw, budget.jsRaw),
            },
        };

        const scopeFailures = [];
        for (const kind of ['css', 'js']) {
            const label = kind.toUpperCase();
            if (totals[kind].gzip > limits[kind].gzip) {
                scopeFailures.push(
                    `${label} comprimido ${kb(totals[kind].gzip)} supera ${kb(limits[kind].gzip)}`,
                );
            }
            if (totals[kind].bytes > limits[kind].raw) {
                scopeFailures.push(
                    `${label} sin comprimir ${kb(totals[kind].bytes)} supera ${kb(limits[kind].raw)}`,
                );
            }
        }

        for (const failure of scopeFailures) failures.push(`${scope.label}: ${failure}`);

        reports.push({
            key: scope.key,
            label: scope.label,
            entries: scope.entries,
            files: rows.map((row) => row.rel),
            rows,
            totals,
            limits,
            failures: scopeFailures,
            threeFiles: rows.filter((row) => row.three).map((row) => row.rel),
            extraEntries: scope.key === 'landing' ? [] : extraEntries,
        });
    }

    // S2: el recurso de la portada (y con él Three.js) no puede llegar a ninguna otra página.
    const landing = reports.find((report) => report.key === 'landing');
    if (landing) {
        for (const report of reports) {
            if (report.key === landing.key) continue;
            const leaked = report.files.filter((file) => landing.files.includes(file));
            if (leaked.length > 0) {
                failures.push(
                    `${report.label}: carga recursos de la portada (${leaked.join(', ')}), y la portada es la única página que puede cargar Three.js`,
                );
            }
            if (report.threeFiles.length > 0) {
                failures.push(
                    `${report.label}: carga Three.js (${report.threeFiles.join(', ')}), que solo puede cargar la portada`,
                );
            }
        }
    }

    return {
        reports,
        scopes: reports,
        failures,
        threeFiles: reports.flatMap((report) => report.threeFiles),
        extraEntries,
    };
}

/** Imprime el informe. Devuelve el texto para poder comprobarlo en las pruebas. */
export function formatReport(result) {
    const lines = [];

    for (const report of result.scopes) {
        lines.push(report.label);
        for (const row of report.rows) {
            const mark = row.three ? '  (Three.js)' : '';
            lines.push(
                `  ${row.rel.padEnd(28)} ${kb(row.bytes).padStart(10)} sin comprimir   ${kb(row.gzip).padStart(9)} gzip${mark}`,
            );
        }
        for (const kind of ['css', 'js']) {
            const used = report.totals[kind];
            const limits = report.limits[kind];
            lines.push(
                `${kind.toUpperCase()}: ${kb(used.gzip)} gzip de ${kb(limits.gzip)} (${((used.gzip / limits.gzip) * 100).toFixed(1)} %) · ${kb(used.bytes)} sin comprimir de ${kb(limits.raw)}`,
            );
        }
        lines.push('');
    }

    if (result.threeFiles.length > 0) {
        lines.push(`Three.js: ${result.threeFiles.join(', ')} (solo en la portada)`);
        lines.push('');
    }

    if (result.failures.length > 0) {
        lines.push('Presupuesto incumplido:');
        for (const failure of result.failures) lines.push(`  - ${failure}`);
    } else {
        lines.push('Dentro del presupuesto.');
    }

    return `${lines.join('\n')}\n`;
}

/** Fuentes: informativas, no presupuestadas. `unicode-range` hace que se descargue solo el subconjunto usado. */
function fontReport(buildDir) {
    const assets = join(buildDir, 'assets');
    if (!existsSync(assets)) return null;

    const fonts = readdirSync(assets).filter((name) => name.endsWith('.woff2'));
    if (fonts.length === 0) return null;

    const bytes = fonts.reduce((sum, name) => sum + statSync(join(assets, name)).size, 0);

    return `  ${'fuentes woff2'.padEnd(28)} ${kb(bytes).padStart(10)} en total, ${fonts.length} subconjuntos (informático)`;
}

function main() {
    try {
        // The CLI must read its own process environment: `WEIGHT_*_KB=… node scripts/check-weight.mjs`
        // is how the limits are lowered to prove the gate actually fails.
        const result = evaluateWeight({ env: process.env });
        process.stdout.write(formatReport(result));

        const buildDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'build');
        const fonts = fontReport(buildDir);
        if (fonts) process.stdout.write(`${fonts}\n`);

        if (result.failures.length > 0) process.exit(1);
    } catch (error) {
        process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
        process.exit(2);
    }
}

// `import.meta.url` llega con la ruta real y `process.argv[1]` con la ruta tal como se invocó:
// en macOS `/var` es un enlace a `/private/var`, así que hay que comparar las rutas reales.
const invokedDirectly = (() => {
    if (process.argv[1] === undefined) return false;
    try {
        return realpathSync(fileURLToPath(import.meta.url)) === realpathSync(process.argv[1]);
    } catch (error) {
        return false;
    }
})();

if (invokedDirectly) main();
