import { expect, test, type Page } from '@playwright/test';

const consoleErrors = (page: Page): string[] => {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(error.message));

  return errors;
};

test.describe('demostración guiada', () => {
  test('va de la portada al resultado con las cifras reales de Saldos', async ({ page }) => {
    const errors = consoleErrors(page);

    await page.goto('/');
    // Issue #74 D1: the landing keeps a single entry, the access screen, and no walkthrough link.
    await page.getByRole('link', { name: 'Entrar a la demo' }).first().click();
    await expect(page).toHaveURL(/\/login$/);

    // The guided demo of #73 keeps its own public route.
    await page.goto('/demo');
    await expect(page).toHaveURL(/\/demo$/);
    await expect(page.getByRole('heading', { name: 'Qué vas a probar' })).toBeVisible();
    await expect(page.locator('li', { hasText: 'Socia Ejemplo A' })).toBeVisible();
    await expect(page.locator('li', { hasText: 'Socio Ejemplo B' })).toBeVisible();
    await expect(page.getByText('TRANSFERENCIA DE EJEMPLO', { exact: true })).toBeVisible();

    // The two amounts are prefilled and each input has its associated label.
    const socia = page.getByLabel(/Socia Ejemplo A/);
    const socio = page.getByLabel(/Socio Ejemplo B/);
    await expect(socia).toHaveValue('40,00');
    await expect(socio).toHaveValue('40,00');

    // The keyboard reaches the field: labels are associated and the control takes the focus.
    await socia.focus();
    await expect(socia).toBeFocused();

    await page.getByRole('button', { name: 'Conciliar 80 €' }).click();
    await expect(page.getByRole('status')).toHaveText('Reparto conciliado desde el extracto.');
    await expect(page.getByRole('heading', { name: 'Resultado' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Saldos' })).toBeVisible();

    const bank = page.locator('.kpis > div', { hasText: 'Sin conciliar' });
    await expect(bank).toContainText('20,00 €');
    await expect(bank).toContainText('1 abono sin conciliar');
    await expect(page.locator('.kpis > div', { hasText: 'Saldo en el banco' })).toContainText('100,00 €');
    await expect(page.locator('.kpis > div', { hasText: 'Movimientos' })).toContainText('1');
    await expect(page.getByRole('heading', { name: /Cuota de \d{4}/ })).toBeVisible();
    await expect(page.getByText('Los 20,00 € que siguen sin conciliar son el resto del abono, no deuda de cuotas.')).toBeVisible();
    await expect(page.getByText('Las cuotas de los dos socios quedan pagadas.')).toBeVisible();

    // A partial split shows the real remainder and the real due state, not a fixed claim.
    await page.goto('/demo');
    await page.getByLabel(/Socia Ejemplo A/).fill('40,00');
    await page.getByLabel(/Socio Ejemplo B/).fill('15,50');
    await page.getByRole('button', { name: 'Conciliar 80 €' }).click();
    await expect(page.getByRole('status')).toHaveText('Reparto conciliado desde el extracto.');
    await expect(page.locator('.kpis > div', { hasText: 'Sin conciliar' })).toContainText('44,50 €');
    await expect(page.locator('.entry', { hasText: 'Cuota de' })).toContainText('24,50 €');
    await expect(page.getByText('Queda 1 cuota pendiente: este reparto no cubre las dos cuotas.')).toBeVisible();
    await expect(page.getByText('Las cuotas de los dos socios quedan pagadas.')).toHaveCount(0);

    // A split that does not fit is refused with the real message and no success is announced.
    await page.goto('/demo');
    await page.getByLabel(/Socia Ejemplo A/).fill('50,00');
    await page.getByRole('button', { name: 'Conciliar 80 €' }).click();
    await expect(page.getByRole('alert')).toContainText('El pago supera lo pendiente de la cuota (40,00 €).');
    await expect(page.getByRole('status')).toHaveCount(0);

    expect(errors).toEqual([]);
  });
});

test.describe('demostración sin JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('recorre el formulario completo y muestra el resultado', async ({ page }) => {
    await page.goto('/demo');

    await expect(page.getByRole('heading', { name: 'Qué vas a probar' })).toBeVisible();
    await expect(page.getByLabel(/Socia Ejemplo A/)).toHaveValue('40,00');
    await expect(page.getByLabel(/Socio Ejemplo B/)).toHaveValue('40,00');

    await page.getByRole('button', { name: 'Conciliar 80 €' }).click();

    await expect(page.getByRole('status')).toHaveText('Reparto conciliado desde el extracto.');
    await expect(page.getByRole('heading', { name: 'Resultado' })).toBeVisible();
    await expect(page.locator('.kpis > div', { hasText: 'Sin conciliar' })).toContainText('20,00 €');
  });
});
