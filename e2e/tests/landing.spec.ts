import { expect, test, type Page } from '@playwright/test';
import { logIn } from '../support/session';

// The landing (issue #60) is the public home page. The scene is progressive enhancement:
// these tests never accept a canvas that hides the explanation, and never accept console errors.

const consoleErrors = (page: Page): string[] => {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(error.message));

  return errors;
};

/** Requests for the extra chunk that only the landing may fetch (Three.js). */
const sceneChunkRequests = (page: Page): string[] => {
  const requests: string[] = [];
  page.on('request', (request) => {
    const url = request.url();
    if (/\/build\/assets\/three\.module-/.test(url)) requests.push(url);
  });

  return requests;
};

/** The lifecycle counters the scene publishes for these tests. */
const sceneState = (page: Page) =>
  page.evaluate(() => {
    const scene = (window as unknown as { __nexoScene?: Record<string, unknown> }).__nexoScene ?? {};

    return scene as { mounted?: boolean; pendingRaf?: number; disposals?: number; disposed?: boolean };
  });

/** Waits until the scene is mounted, and skips the test where the browser cannot run WebGL2. */
const mountScene = async (page: Page): Promise<boolean> => {
  await page.goto('/', { waitUntil: 'networkidle' });
  const visible = await page.locator('#lp-canvas').isVisible();
  if (visible) await page.waitForFunction(() => Boolean((window as any).__nexoScene?.mounted));

  return visible;
};

test.describe('portada', () => {
  test('explica el proyecto, ofrece una sola acción principal y no deja errores en consola', async ({ page }) => {
    const errors = consoleErrors(page);
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toContainText('Las cuentas');
    await expect(page.getByRole('link', { name: 'Entrar a la demo' }).first()).toHaveAttribute('href', '/login');

    // The four chapters exist as text even when the scene is running.
    for (const heading of [
      'Una cuota entra en la asociación.',
      'El banco habla en CSV.',
      'Un abono, dos cuotas.',
      'La junta lee. La tesorería trabaja.',
    ]) {
      await expect(page.getByRole('heading', { name: heading })).toBeVisible();
    }

    await expect(page.getByText('Qué incluye la demo')).toBeVisible();
    await expect(page.getByText('No hay cobros reales ni conexión bancaria').first()).toBeVisible();
    await expect(page.getByText('Esta demo no garantiza cumplimiento fiscal, contable o legal.')).toBeVisible();

    // Either the WebGL scene or its static fallback: never neither, never a blank page.
    const scene = page.locator('#lp-canvas');
    const fallback = page.locator('.lp-fallback');
    const canvasVisible = await scene.isVisible();
    const fallbackVisible = await fallback.isVisible();
    expect(canvasVisible || fallbackVisible, 'la portada muestra la escena o su respaldo').toBe(true);

    if (canvasVisible) {
      // When the scene runs, the visitor can stop it. The accessible name changes with state,
      // so the test follows the control by its stable id.
      const pause = page.locator('#landing-pause');
      await expect(pause).toBeVisible();
      await expect(pause).toHaveAttribute('aria-pressed', 'false');
      await pause.click();
      await expect(pause).toHaveAttribute('aria-pressed', 'true');
      await expect(pause).toContainText('Reanudar escena');
    }

    expect(errors).toEqual([]);
  });

  test('el recurso de la portada no llega al acceso ni a una pantalla con sesión', async ({ page }) => {
    const requests = sceneChunkRequests(page);

    await page.goto('/login');
    expect(await page.evaluate(() => document.documentElement.outerHTML.includes('/build/assets/landing-'))).toBe(false);

    await logIn(page, 'board');
    expect(await page.evaluate(() => document.documentElement.outerHTML.includes('/build/assets/landing-'))).toBe(false);
    expect(requests).toEqual([]);
  });

  test('el teclado salta al contenido, llega a la acción y deja el foco a la vista', async ({ page }) => {
    await page.goto('/');

    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: 'Saltar al contenido' });
    await expect(skip).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#contenido$/);
    await expect(page.locator('#contenido')).toBeFocused();

    // The first stop inside the page is the one primary action, and its focus ring is visible.
    await page.keyboard.press('Tab');
    const cta = page.getByRole('link', { name: 'Entrar a la demo' }).first();
    await expect(cta).toBeFocused();
    const ring = await cta.evaluate((element) => {
      const style = getComputedStyle(element);

      return `${style.outlineStyle} ${style.outlineWidth}`;
    });
    expect(ring).toBe('solid 2px');
  });

  test('ninguna pantalla de la portada desborda horizontalmente a 360 px', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

    await page.goto('/');
    expect(await overflow(), 'apertura a 360 px').toBeLessThanOrEqual(0);

    await page.locator('#cierre').scrollIntoViewIfNeeded();
    expect(await overflow(), 'cierre a 360 px').toBeLessThanOrEqual(0);

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    expect(await overflow(), 'fondo de la portada a 360 px').toBeLessThanOrEqual(0);
  });
});

test.describe('portada con movimiento reducido', () => {
  test.use({ reducedMotion: 'reduce' });

  test('deja el fotograma estático, no descarga la escena y conserva todo el texto', async ({ page }) => {
    const errors = consoleErrors(page);
    const requests = sceneChunkRequests(page);

    await page.goto('/');

    await expect(page.locator('.lp-fallback')).toBeVisible();
    await expect(page.locator('#lp-canvas')).toBeHidden();
    await expect(page.getByRole('button', { name: /Pausar la animación/ })).toBeHidden();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Las cuentas');
    await expect(page.getByText('Desplázate para viajar')).toBeVisible();

    // Moving is what reduced motion refuses: the extra chunk is never fetched.
    expect(requests).toEqual([]);
    expect(errors).toEqual([]);
  });
});

test.describe('portada sin WebGL', () => {
  test.beforeEach(async ({ page }) => {
    // A browser without WebGL exposed: the page must fall back, not break.
    await page.addInitScript(() => {
      const original = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function (this: HTMLCanvasElement, type: string, ...rest: unknown[]) {
        if (typeof type === 'string' && type.startsWith('webgl')) return null;

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        return (original as any).call(this, type, ...rest);
      } as typeof HTMLCanvasElement.prototype.getContext;
    });
  });

  test('muestra el respaldo estático, sin errores y sin descargar Three.js', async ({ page }) => {
    const errors = consoleErrors(page);
    const requests = sceneChunkRequests(page);

    await page.goto('/');

    await expect(page.locator('.lp-fallback')).toBeVisible();
    await expect(page.locator('#lp-canvas')).toBeHidden();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Las cuentas');
    await expect(page.getByText('Cuentas claras para el siguiente equipo.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Entrar a la demo' }).first()).toHaveAttribute('href', '/login');

    expect(requests).toEqual([]);
    expect(errors).toEqual([]);
  });

  // Three.js only renders on WebGL2. A browser that only exposes WebGL1 must get the static
  // frame without downloading the library and without a single console error.
  test('con solo WebGL1 deja el respaldo y no descarga Three.js', async ({ page }) => {
    await page.addInitScript(() => {
      const original = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function (this: HTMLCanvasElement, type: string, ...rest: unknown[]) {
        if (type === 'webgl2') return null;
        if (type === 'webgl') return {};

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        return (original as any).call(this, type, ...rest);
      } as typeof HTMLCanvasElement.prototype.getContext;
    });

    const errors = consoleErrors(page);
    const requests = sceneChunkRequests(page);

    await page.goto('/', { waitUntil: 'networkidle' });

    await expect(page.locator('.lp-fallback')).toBeVisible();
    await expect(page.locator('#lp-canvas')).toBeHidden();
    expect(requests).toEqual([]);
    expect(errors).toEqual([]);
  });
});

test.describe('portada: ciclo de vida de la escena', () => {
  test('la escena se detiene cuando no se ve y vuelve al volver', async ({ page }) => {
    if (!(await mountScene(page))) test.skip(true, 'Este navegador no expone WebGL2');

    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(1);

    // The manifesto and the closing section are opaque: the fixed scene is covered and must stop.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(0);

    await page.evaluate(() => window.scrollTo(0, 0));
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(1);
  });

  test('la pausa detiene el bucle y ni la visibilidad ni el scroll lo reinician', async ({ page }) => {
    if (!(await mountScene(page))) test.skip(true, 'Este navegador no expone WebGL2');

    const pause = page.locator('#landing-pause');
    await pause.click();
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(0);

    // Leaving and returning to the tab must not start a paused scene.
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    expect((await sceneState(page)).pendingRaf).toBe(0);

    await pause.click();
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(1);
  });

  test('cambiar a movimiento reducido apaga la escena y libera sus recursos', async ({ page }) => {
    if (!(await mountScene(page))) test.skip(true, 'Este navegador no expone WebGL2');

    await page.emulateMedia({ reducedMotion: 'reduce' });

    await expect(page.locator('.lp-fallback')).toBeVisible();
    await expect(page.locator('#lp-canvas')).toBeHidden();
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(0);

    const state = await sceneState(page);
    expect(state.disposed, 'la escena se marca como desmontada').toBe(true);
    expect(state.disposals, 'geometrías, materiales, texturas y renderer se liberan').toBeGreaterThanOrEqual(4);
  });

  test('perder el contexto WebGL suelta los manejadores y no vuelve a pintar', async ({ page }) => {
    if (!(await mountScene(page))) test.skip(true, 'Este navegador no expone WebGL2');

    const lost = await page.evaluate(() => {
      const canvas = document.getElementById('lp-canvas') as HTMLCanvasElement;
      const gl = canvas.getContext('webgl2');
      const extension = gl?.getExtension('WEBGL_lose_context');
      extension?.loseContext();

      return Boolean(extension);
    });
    if (!lost) test.skip(true, 'Este navegador no expone WEBGL_lose_context');

    await expect(page.locator('.lp-fallback')).toBeVisible();
    await expect.poll(async () => (await sceneState(page)).pendingRaf).toBe(0);
    await expect.poll(async () => (await sceneState(page)).disposed).toBe(true);

    // A tab return after the teardown must not render on the hidden canvas.
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => window.dispatchEvent(new Event('resize')));
    expect((await sceneState(page)).pendingRaf).toBe(0);
    await expect(page.locator('#lp-canvas')).toBeHidden();
  });
});
