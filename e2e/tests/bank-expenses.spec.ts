import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

// Issue #65: a charge of the statement can be classified as an expense. The bank balance already
// subtracts it, so the point of the flow is that the spending is explained without the figure moving.
test('tesorería clasifica un cargo como gasto, la junta lo consulta y se anula con motivo', async ({ page }) => {
  await logIn(page, 'treasury');

  // A statement with one charge, so the page offers a classification form for it.
  const stamp = Date.now();
  await page.goto('/bank');
  await page.getByLabel('Archivo CSV').setInputFiles({
    name: `gastos-${stamp}.csv`,
    mimeType: 'text/csv',
    buffer: Buffer.from(`fecha;importe;concepto\n2026-02-10;-25,50;COMPRA MATERIAL ${stamp}\n`, 'utf8'),
  });
  await page.getByRole('button', { name: 'Importar' }).click();
  await expect(page.locator('section[aria-label="Resultado de la importación"]')).toContainText('Importados');

  const movement = page.locator('article.entry', { hasText: `COMPRA MATERIAL ${stamp}` });
  await expect(movement).toContainText('-25,50 €');

  await movement.getByLabel('Categoría').selectOption('material');
  await movement.getByLabel('Descripción').fill('Material de taller');
  await movement.getByRole('button', { name: 'Clasificar el cargo' }).click();
  await expect(page.getByRole('status')).toHaveText('Cargo clasificado como gasto.');
  await expect(movement).toContainText('Clasificado como gasto · Material · 25,50 €');

  // The bank balance still subtracts the charge exactly once.
  await page.goto('/saldos');
  await expect(page.locator('section[aria-label="Banco"]')).toContainText('Gastos bancarios');
  await expect(page.locator('section[aria-label="Banco"]')).toContainText('25,50 €');

  // The board sees the classification and no way to write.
  await logIn(page, 'board');
  await page.goto('/bank');
  const seen = page.locator('article.entry', { hasText: `COMPRA MATERIAL ${stamp}` });
  await expect(seen).toContainText('Clasificado como gasto');
  await expect(seen.getByRole('button', { name: 'Clasificar el cargo' })).toHaveCount(0);
  await expect(seen.getByRole('button', { name: 'Anular el gasto' })).toHaveCount(0);

  // Treasury voids it with a reason and the charge is free again.
  await logIn(page, 'treasury');
  await page.goto('/bank');
  const voided = page.locator('article.entry', { hasText: `COMPRA MATERIAL ${stamp}` });
  await voided.getByLabel('Motivo de la anulación').fill('Era otro gasto');
  await voided.getByRole('button', { name: 'Anular el gasto' }).click();
  await expect(page.getByRole('status')).toHaveText('Gasto anulado: el cargo vuelve a estar sin clasificar.');
  await expect(page.locator('article.entry', { hasText: `COMPRA MATERIAL ${stamp}` })).toContainText('Clasificar el cargo');
});
