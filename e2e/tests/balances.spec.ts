import { expect, test, type Page } from '@playwright/test';
import { logIn } from '../support/session';

const uploadLabel = 'Archivo CSV con las columnas fecha, importe y concepto';

/** Reads one euro figure of the bank block and turns it into cents. */
async function figure(page: Page, label: string): Promise<number> {
  const box = page.locator('.kpis > div', { hasText: label });
  const text = await box.innerText();
  const match = text.match(/(\d{1,3}(?:\.\d{3})*,\d{2}) €/);

  return match ? Math.round(Number(match[1].replace(/\./g, '').replace(',', '.')) * 100) : 0;
}

async function bankFigures(page: Page): Promise<{ unreconciled: number; balance: number }> {
  await page.goto('/saldos');

  if (await page.getByText('Todavía no se ha importado ningún extracto').isVisible()) {
    return { unreconciled: 0, balance: 0 };
  }

  return { unreconciled: await figure(page, 'Sin conciliar'), balance: await figure(page, 'Saldo en el banco') };
}

test('junta consulta los saldos y la pantalla no ofrece ninguna acción de escritura', async ({ page }) => {
  await logIn(page, 'board');
  await page.goto('/dashboard');

  const nav = page.getByRole('navigation', { name: 'Principal' });
  await nav.getByRole('link', { name: 'Saldos', exact: true }).click();

  await expect(page).toHaveURL(/\/saldos$/);
  await expect(nav.getByRole('link', { name: 'Saldos', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('heading', { name: 'Saldos', level: 1 })).toBeVisible();
  await expect(page.getByText('Cuotas pendientes por ejercicio')).toBeVisible();
  await expect(page.locator('#contenido form')).toHaveCount(0);
  await expect(page.locator('#contenido button[type="submit"]')).toHaveCount(0);
});

test('un abono importado aparece como pendiente de conciliar y suma en el saldo', async ({ page }) => {
  const stamp = Date.now();
  const amount = 4550;
  const csv = ['fecha;importe;concepto', `05/03/2026;45,50;Ingreso suelto ${stamp}`].join('\n');

  await logIn(page, 'treasury');
  const before = await bankFigures(page);

  await page.goto('/bank');
  await page.getByLabel(uploadLabel).setInputFiles({ name: 'extracto.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.getByRole('button', { name: 'Importar extracto' }).click();
  await expect(page.getByRole('status', { name: 'Resultado de la importación' })).toContainText(/Importados\s*1/);

  const after = await bankFigures(page);
  expect(after.unreconciled).toBe(before.unreconciled + amount);
  expect(after.balance).toBe(before.balance + amount);
  await expect(page.getByText('abonos sin conciliar')).toBeVisible();
});
