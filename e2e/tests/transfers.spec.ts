import { expect, test, type Page } from '@playwright/test';
import { logIn } from '../support/session';

// The e2e database is shared by every spec, so the figures are compared before and after the
// transfer instead of against a fixed amount: what matters is that only the cash moves.
function euros(text: string | null): number {
  const match = (text ?? '').match(/-?\d+[.,]\d{2}/);

  return match ? Number(match[0].replace('.', '').replace(',', '.')) : Number.NaN;
}

async function cashCents(page: Page): Promise<number> {
  const text = await page.locator('section[aria-label="Caja"] .n').first().textContent();

  return Math.round(euros(text) * 100);
}

async function bankCents(page: Page): Promise<number> {
  const text = await page.locator('section[aria-label="Banco"] .n').first().textContent();

  return Math.round(euros(text) * 100);
}

test('tesorería marca una retirada como traspaso y la caja sube sin mover el banco', async ({ page }) => {
  await logIn(page, 'treasury');
  const stamp = Date.now();

  await page.goto('/saldos');
  const cashBefore = await cashCents(page);
  const bankBefore = await bankCents(page);

  await page.goto('/bank');
  await page.getByLabel('Archivo CSV').setInputFiles({
    name: `traspaso-${stamp}.csv`,
    mimeType: 'text/csv',
    buffer: Buffer.from(`fecha;importe;concepto\n2026-02-12;-80,00;RETIRADA EFECTIVO ${stamp}\n`, 'utf8'),
  });
  await page.getByRole('button', { name: 'Importar' }).click();
  await expect(page.locator('section[aria-label="Resultado de la importación"]')).toContainText('Importados');

  const movement = page.locator('article.entry', { hasText: `RETIRADA EFECTIVO ${stamp}` });
  await movement.getByLabel('Descripción del traspaso').fill('Retirada para la caja');
  await movement.getByRole('button', { name: 'Marcar traspaso' }).click();
  await expect(page.getByRole('status')).toHaveText('Movimiento marcado como traspaso.');
  await expect(movement).toContainText('Traspaso · Del banco a la caja · 80,00 €');

  // The safe grew by the withdrawal; the bank only reflects the imported statement.
  await page.goto('/saldos');
  await expect.poll(async () => cashCents(page)).toBe(cashBefore + 8000);
  await expect.poll(async () => bankCents(page)).toBe(bankBefore - 8000);

  // The board sees the transfer and no way to write.
  await logIn(page, 'board');
  await page.goto('/bank');
  const seen = page.locator('article.entry', { hasText: `RETIRADA EFECTIVO ${stamp}` });
  await expect(seen).toContainText('Traspaso');
  await expect(seen.getByRole('button', { name: 'Anular el traspaso' })).toHaveCount(0);

  // Voiding it takes the money back out of the safe and frees the movement.
  await logIn(page, 'treasury');
  await page.goto('/bank');
  const voided = page.locator('article.entry', { hasText: `RETIRADA EFECTIVO ${stamp}` });
  await voided.getByLabel('Motivo de la anulación').fill('No era una retirada');
  await voided.getByRole('button', { name: 'Anular el traspaso' }).click();
  await expect(page.getByRole('status')).toContainText('Traspaso anulado');

  await page.goto('/saldos');
  await expect.poll(async () => cashCents(page)).toBe(cashBefore);
});
