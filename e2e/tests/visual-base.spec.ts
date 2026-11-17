import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

// Same value as --color-canvas in resources/css/app.css, as the browser reports it.
const canvas = 'rgb(32, 38, 52)';

test.describe('base visual', () => {
  // The default look is the dark one; the light one has its own tests below.
  test.use({ colorScheme: 'dark' });

  test('la hoja de estilos compilada se carga en la página pública y en la de acceso', async ({ page }) => {
    for (const path of ['/', '/login']) {
      await page.goto(path);
      await expect(page.locator('link[rel="stylesheet"]')).toHaveCount(1);
      expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor), path).toBe(canvas);
    }
  });

  test('el teclado llega primero al enlace de saltar al contenido, que mueve el foco', async ({ page }) => {
    await logIn(page, 'treasury');
    await page.goto('/members');
    await page.keyboard.press('Tab');

    const skip = page.getByRole('link', { name: 'Saltar al contenido' });
    await expect(skip).toBeFocused();
    expect((await skip.boundingBox())?.y).toBeGreaterThanOrEqual(0);

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#contenido$/);
    await expect(page.locator('#contenido')).toBeFocused();
  });

  test('la navegación marca el apartado actual y deja ver la identidad', async ({ page }) => {
    await logIn(page, 'board');
    const nav = page.getByRole('navigation', { name: 'Principal' });

    for (const [path, name] of [
      ['/dashboard', 'Panel'],
      ['/members', 'Socios'],
      ['/fees', 'Cuotas'],
      ['/bank', 'Movimientos'],
    ] as const) {
      await page.goto(path);
      await expect(nav.getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page');
      await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
    }
    await expect(page.getByRole('banner')).toContainText('Junta');
  });

  test('ninguna pantalla desborda horizontalmente a 360 px de ancho', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

    for (const path of ['/', '/login']) {
      await page.goto(path);
      expect(await overflow(), path).toBeLessThanOrEqual(0);
    }

    await logIn(page, 'treasury');
    for (const path of ['/dashboard', '/members', '/members/create', '/fees', '/fees/create', '/bank']) {
      await page.goto(path);
      expect(await overflow(), path).toBeLessThanOrEqual(0);
    }

    // The densest screen: a fee year with one member, so the payment and exemption forms are rendered.
    // The member joins before the year starts (2020 < 2023), which is what assigns them that year's fee.
    const stamp = Date.now();
    await page.goto('/members/create');
    await page.getByLabel('Nombre completo').fill(`Socia móvil ${stamp}`);
    await page.getByLabel('Correo ficticio (.test)').fill(`movil.${stamp}@example.test`);
    await page.getByLabel('Fecha de alta').fill('2020-01-01');
    await page.getByRole('button', { name: 'Crear socio' }).click();
    await expect(page.getByRole('heading', { name: `Socia móvil ${stamp}` })).toBeVisible();
    await page.goto('/fees/create');
    await page.getByLabel('Año').fill('2023');
    await page.getByLabel('Importe en euros').fill('30');
    await page.getByRole('button', { name: 'Fijar cuota' }).click();
    await expect(page.getByRole('heading', { name: 'Cuota de 2023' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Registrar pago' }).first()).toBeVisible();
    expect(await overflow(), 'detalle de la cuota').toBeLessThanOrEqual(0);
  });

  test('un filtro sin socios ofrece el siguiente paso y el menú marca la sección actual', async ({ page }) => {
    await logIn(page, 'treasury');
    await page.goto('/members?estado=bajas');
    await expect(page.getByText('No hay socios que mostrar con este filtro.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Añadir un socio' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Socios' })).toHaveAttribute('aria-current', 'page');
  });

  test('el panel resume socios activos, lo pendiente y lo cobrado, o explica por qué no hay cifras', async ({ page }) => {
    await logIn(page, 'board');
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: /^Panel/ })).toBeVisible();
    await expect(page.getByText('Extracto actualizado el').or(page.getByText('Sin extracto importado todavía'))).toBeVisible();
    await expect(page.getByText('Socios activos').or(page.getByText('Todavía no hay cuotas fijadas'))).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Siguientes pasos' })).toBeVisible();
  });

  test('al imprimir solo queda el contenido: sin navegación, sin formularios ni botones', async ({ page }) => {
    await logIn(page, 'treasury');
    await page.goto('/bank');
    await expect(page.getByRole('button', { name: 'Importar extracto' })).toBeVisible();
    await page.emulateMedia({ media: 'print' });
    await expect(page.getByRole('navigation', { name: 'Principal' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Importar extracto' })).toBeHidden();
    await expect(page.getByRole('heading', { name: 'Movimientos bancarios' })).toBeVisible();
  });
});

test.describe('modo claro', () => {
  const light = 'rgb(221, 227, 236)';
  const dark = 'rgb(32, 38, 52)';
  const background = (page: import('@playwright/test').Page) => page.evaluate(() => getComputedStyle(document.body).backgroundColor);

  test.describe('sin elección previa', () => {
    test.use({ colorScheme: 'light' });

    test('sigue la preferencia clara del sistema', async ({ page }) => {
      await page.goto('/');
      expect(await background(page)).toBe(light);
    });
  });

  test.describe('con la preferencia oscura del sistema', () => {
    test.use({ colorScheme: 'dark' });

    test('el botón cambia de tema, lo recuerda al recargar y se puede revertir', async ({ page }) => {
      await page.goto('/');
      expect(await background(page)).toBe(dark);

      await page.getByRole('button', { name: 'Modo claro' }).click();
      expect(await background(page)).toBe(light);
      await expect(page.getByRole('button', { name: 'Modo oscuro' })).toBeVisible();

      await page.reload();
      expect(await background(page)).toBe(light);

      await page.getByRole('button', { name: 'Modo oscuro' }).click();
      expect(await background(page)).toBe(dark);
    });
  });
});
