// Verifica los disparadores reales de .github/workflows/ci.yml.
//
// El workflow es la fuente de verdad: este archivo lee su apartado `on:`, extrae los
// patrones de rama declarados y evalúa con las reglas de GitHub (`*` no cruza `/`,
// `**` sí) si una PR o un push concreto ejecutaría las comprobaciones. Si el workflow
// usa una sintaxis que este verificador no entiende, falla en voz alta en vez de
// aprobar por omisión.
//
// Se ejecuta con `node --test scripts/test-ci-trigger.mjs` (también en el propio CI).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const workflowPath = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '.github',
  'workflows',
  'ci.yml',
);

const workflow = readFileSync(workflowPath, 'utf8');

const indentation = (line) => line.length - line.trimStart().length;

const isSkippable = (line) => {
  const trimmed = line.trim();

  return trimmed === '' || trimmed.startsWith('#');
};

/** Líneas del apartado `on:` del workflow. */
function onBlock() {
  const all = workflow.split(/\r?\n/);
  const start = all.findIndex((line) => /^on:(\s|$)/.test(line));

  assert.notEqual(start, -1, 'ci.yml no declara el apartado `on:`');
  assert.equal(
    all[start].trim(),
    'on:',
    'el apartado `on:` debe declararse como bloque para poder verificarlo',
  );

  const block = [];

  for (let i = start + 1; i < all.length; i += 1) {
    if (isSkippable(all[i])) {
      block.push(all[i]);
      continue;
    }

    if (indentation(all[i]) === 0) {
      break;
    }

    block.push(all[i]);
  }

  return block;
}

/** Líneas del bloque de un evento (`pull_request` o `push`), o null si no existe. */
function eventBlock(event) {
  const block = onBlock();
  const start = block.findIndex((line) => new RegExp(`^ {2}${event}:(\\s|$)`).test(line));

  if (start === -1) {
    return null;
  }

  assert.equal(
    block[start].trim(),
    `${event}:`,
    `el disparador ${event} debe declararse como bloque para poder verificarlo`,
  );

  const lines = [];

  for (let i = start + 1; i < block.length; i += 1) {
    if (isSkippable(block[i])) {
      lines.push(block[i]);
      continue;
    }

    if (indentation(block[i]) <= 2) {
      break;
    }

    lines.push(block[i]);
  }

  return lines;
}

function scalar(value) {
  const quoted = /^(['"])(.*)\1$/.exec(value);

  return quoted ? quoted[2] : value;
}

/** Patrones de rama del evento. Una lista vacía significa «cualquier rama». */
function branchPatterns(event) {
  const lines = eventBlock(event);

  if (lines === null) {
    return [];
  }

  assert.equal(
    lines.some((line) => /^ {4}branches-ignore:/.test(line)),
    false,
    `el verificador no entiende \`branches-ignore\` en ${event}`,
  );

  const start = lines.findIndex((line) => /^ {4}branches:(\s|$)/.test(line));

  if (start === -1) {
    return [];
  }

  const inline = lines[start].replace(/^ {4}branches:/, '').trim();

  if (inline !== '') {
    assert.ok(inline.startsWith('[') && inline.endsWith(']'), `no se entiende \`${inline}\``);

    return inline
      .slice(1, -1)
      .split(',')
      .map((item) => item.trim())
      .filter((item) => item !== '')
      .map(scalar);
  }

  const patterns = [];

  for (let i = start + 1; i < lines.length; i += 1) {
    if (isSkippable(lines[i])) {
      continue;
    }

    if (indentation(lines[i]) <= 4) {
      break;
    }

    const item = /^\s*-\s+(.+)$/.exec(lines[i]);

    assert.notEqual(item, null, `no se entiende la entrada de \`branches\`: ${lines[i].trim()}`);
    patterns.push(scalar(item[1].trim()));
  }

  return patterns;
}

/** Reglas de GitHub: `*` no cruza `/` y `**` sí. La sintaxis extendida no se admite. */
function patternToRegExp(pattern) {
  let source = '';

  for (let i = 0; i < pattern.length; i += 1) {
    const char = pattern[i];

    if (char === '*') {
      if (pattern[i + 1] === '*') {
        source += '.*';
        i += 1;
      } else {
        source += '[^/]*';
      }

      continue;
    }

    assert.equal(
      '+?[]!'.includes(char),
      false,
      `el verificador no entiende la sintaxis extendida de «${pattern}»`,
    );
    source += char.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  return new RegExp(`^${source}$`);
}

/** ¿El evento se dispara sobre esa rama según los patrones del workflow real? */
function triggers(event, branch) {
  const patterns = branchPatterns(event);

  if (patterns.length === 0) {
    return true;
  }

  return patterns.some((pattern) => patternToRegExp(pattern).test(branch));
}

test('una PR hacia una rama de la cadena #44 recibe las comprobaciones', () => {
  for (const branch of ['feat/44-income-integrity', 'feat/44-other-income']) {
    assert.equal(triggers('pull_request', branch), true, `la PR hacia ${branch} no ejecuta el CI`);
  }
});

test('una PR hacia una rama ajena a la cadena #44 no recibe las comprobaciones', () => {
  for (const branch of [
    'fix/38-stale-reconciliation',
    'feat/45-otra-mejora',
    'feat/446-sin-guion',
    'feat/44',
    'feat/44-other-income/anidada',
  ]) {
    assert.equal(triggers('pull_request', branch), false, `la PR hacia ${branch} ejecuta el CI`);
  }
});

test('una PR hacia main conserva las comprobaciones', () => {
  assert.equal(triggers('pull_request', 'main'), true);
});

test('un push a main conserva las comprobaciones', () => {
  assert.equal(triggers('push', 'main'), true);
});

test('el push no se dispara fuera de main', () => {
  assert.deepEqual(branchPatterns('push'), ['main']);

  for (const branch of ['feat/44-income-integrity', 'feat/44-other-income', 'fix/1-algo']) {
    assert.equal(triggers('push', branch), false, `el push a ${branch} ejecuta el CI`);
  }
});

test('el filtro de e2e y la cancelación de ejecuciones obsoletas se conservan', () => {
  assert.match(workflow, /^\s+if: needs\.changes\.outputs\.e2e == 'true'$/m);
  assert.match(
    workflow,
    /^\s+cancel-in-progress: \$\{\{ github\.event_name == 'pull_request' \}\}$/m,
  );
  assert.match(
    workflow,
    /grep -Eq '\^\(app\|bootstrap\|config\|database\|e2e\|public\|resources\|routes\|scripts\)\//,
  );
});

test('el CI ejecuta esta verificación', () => {
  assert.match(workflow, /^\s+run: node --test scripts\/test-ci-trigger\.mjs$/m);
});
