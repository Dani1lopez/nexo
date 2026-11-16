import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

const uploadLabel = 'Archivo CSV con las columnas fecha, importe y concepto';

test('tesorería importa un extracto, ve las filas rechazadas y la reimportación se detecta; junta solo consulta', async ({ page, browser }) => {
  const stamp = Date.now();
  const csv = [
    'fecha;importe;concepto',
    `01/03/2026;10,50;Cuota Ana ${stamp}`,
    '31/02/2026;5,00;Fecha imposible',
    `2026-03-02;-20;Recibo luz ${stamp}`,
  ].join('\n');
  const file = { name: 'extracto.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) };
  const report = page.getByRole('status', { name: 'Resultado de la importación' });

  await logIn(page, 'treasury');
  await page.goto('/bank');
  await page.getByLabel(uploadLabel).setInputFiles(file);
  await page.getByRole('button', { name: 'Importar extracto' }).click();

  await expect(page).toHaveURL(/\/bank$/);
  await expect(report).toContainText(/Importados\s*2/);
  await expect(report).toContainText(/Duplicados omitidos\s*0/);
  await expect(report).toContainText(/Rechazados\s*1/);
  await expect(report).toContainText('Línea 3');
  await expect(page.getByRole('heading', { name: `Cuota Ana ${stamp}` })).toBeVisible();
  await expect(page.locator('article', { has: page.getByRole('heading', { name: `Recibo luz ${stamp}` }) })).toContainText('-20,00 €');

  // The same file again creates nothing new: both valid rows are duplicates, the bad row is rejected again.
  await page.getByLabel(uploadLabel).setInputFiles(file);
  await page.getByRole('button', { name: 'Importar extracto' }).click();
  await expect(report).toContainText(/Importados\s*0/);
  await expect(report).toContainText(/Duplicados omitidos\s*2/);
  await expect(page.getByRole('heading', { name: `Cuota Ana ${stamp}` })).toHaveCount(1);

  // A file that is not a statement is refused as a whole.
  await page.getByLabel(uploadLabel).setInputFiles({ name: 'otro.csv', mimeType: 'text/csv', buffer: Buffer.from('a;b;c\n1;2;3\n') });
  await page.getByRole('button', { name: 'Importar extracto' }).click();
  await expect(page.getByRole('alert')).toContainText('El archivo debe tener las columnas fecha, importe y concepto');

  // Board sees the imported movements but has no way to upload.
  const boardContext = await browser.newContext({ baseURL: page.url().replace(/\/bank$/, ''), locale: 'es-ES' });
  const boardPage = await boardContext.newPage();
  await logIn(boardPage, 'board');
  await boardPage.goto('/bank');
  await expect(boardPage.getByRole('heading', { name: `Cuota Ana ${stamp}` })).toBeVisible();
  await expect(boardPage.getByRole('button', { name: 'Importar extracto' })).toHaveCount(0);
  await boardContext.close();
});
