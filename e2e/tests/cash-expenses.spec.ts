import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

// Issue #62: the money of the safe moves in one direction only. The draft waits, the confirmation
// subtracts once, the void returns the money and keeps the row, and the board reads but never writes.
test.describe.configure({ mode: 'serial' });

test('tesorería fija la base, registra un gasto, lo confirma y lo anula', async ({ page }) => {
  await logIn(page, 'treasury');
  await page.goto('/caja');

  await page.getByLabel('Saldo inicial en euros', { exact: false }).fill('80');
  await page.getByRole('button', { name: 'Fijar saldo inicial' }).click();
  await expect(page).toHaveURL(/\/caja$/);
  await expect(page.getByRole('status')).toHaveText('Saldo inicial de caja fijado.');
  await expect(page.locator('section[aria-label="Resumen de caja"]')).toContainText('80,00 €');

  const today = new Date().toISOString().slice(0, 10);
  await page.getByLabel('Fecha', { exact: true }).fill(today);
  await page.getByLabel('Importe en euros').fill('25');
  await page.getByLabel('Concepto').fill('Material para el taller');
  await page.getByLabel('Tengo el justificante').check();
  await page.getByRole('button', { name: 'Guardar en borrador' }).click();
  await expect(page.getByRole('status')).toHaveText('Gasto en borrador registrado.');
  await expect(page.locator('tbody')).toContainText('Borrador');
  // A draft does not move the money yet.
  await expect(page.locator('section[aria-label="Resumen de caja"]')).toContainText('80,00 €');

  await page.getByRole('button', { name: 'Confirmar' }).click();
  await expect(page.getByRole('status')).toHaveText('Gasto confirmado: descontado de la caja.');
  await expect(page.locator('section[aria-label="Resumen de caja"]')).toContainText('55,00 €');

  await page.getByLabel(/Motivo de la anulación del gasto/).fill('Se anula para repetirlo');
  await page.getByRole('button', { name: 'Anular gasto' }).click();
  await expect(page.getByRole('status')).toHaveText('Gasto anulado: el importe ha vuelto a la caja.');
  await expect(page.locator('section[aria-label="Resumen de caja"]')).toContainText('80,00 €');
  await expect(page.locator('tbody')).toContainText('Anulado — Se anula para repetirlo');

  // The bank balance never moves on this page.
  await page.goto('/saldos');
  await expect(page.locator('section[aria-label="Caja"]')).toContainText('Saldo en caja');
  await expect(page.locator('section[aria-label="Caja"]')).toContainText('80,00 €');
});

test('junta consulta la caja y no encuentra ninguna acción de escritura', async ({ page }) => {
  await logIn(page, 'board');
  await page.goto('/caja');

  await expect(page.locator('section[aria-label="Resumen de caja"]')).toContainText('80,00 €');
  await expect(page.locator('tbody')).toContainText('Material para el taller');
  await expect(page.locator('tbody')).toContainText('Anulado');
  await expect(page.getByRole('button', { name: 'Guardar en borrador' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Anular gasto' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Fijar saldo inicial' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Editar' })).toHaveCount(0);
});
