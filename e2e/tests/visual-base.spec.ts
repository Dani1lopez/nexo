import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

// Same value as --color-canvas in resources/css/app.css, as the browser reports it.
const canvas = 'rgb(237, 244, 239)';

test.describe('base visual', () => {
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

    // The densest screen: a year with one member, so the payment and exemption forms are rendered.
    await page.goto('/members/create');
    await page.getByLabel('Nombre completo').fill(`Socia móvil ${Date.now()}`);
    await page.getByLabel('Correo ficticio (.test)').fill(`movil.${Date.now()}@example.test`);
    await page.getByLabel('Fecha de alta').fill('2020-01-01');
    await page.getByRole('button', { name: 'Crear socio' }).click();
    await page.goto('/fees/create');
    await page.getByLabel('Año').fill('2023');
    await page.getByLabel('Importe en euros').fill('30');
    await page.getByRole('button', { name: 'Fijar cuota' }).click();
    await expect(page.getByRole('button', { name: 'Registrar pago' }).first()).toBeVisible();
    expect(await overflow(), 'detalle de la cuota').toBeLessThanOrEqual(0);
  });
});
