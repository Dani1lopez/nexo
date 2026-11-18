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

  // A fresh database shows the empty state instead of the bank block.
  if (await page.getByText('Todavía no se ha importado ningún extracto').isVisible()) {
    return { unreconciled: 0, balance: 0 };
  }

  return { unreconciled: await figure(page, 'Sin conciliar'), balance: await figure(page, 'Saldo en el banco') };
}

test('tesorería clasifica un abono como otro ingreso, la junta lo consulta y se anula con motivo', async ({ page }) => {
  const stamp = Date.now();
  const concept = `Donación mercadillo ${stamp}`;
  const charge = `Compra material ${stamp}`;
  const description = `Donación del mercadillo de ${stamp}`;

  await logIn(page, 'treasury');

  // A pending due exists, so the credit is offered for both destinations and only one may take it.
  await page.goto('/members/create');
  await page.getByLabel('Nombre completo').fill(`Socia otros ingresos ${stamp}`);
  await page.getByLabel('Correo ficticio (.test)').fill(`otros.ingresos.${stamp}@example.test`);
  await page.getByLabel('Fecha de alta').fill('2020-01-01');
  await page.getByRole('button', { name: 'Crear socio' }).click();
  await expect(page.getByRole('heading', { name: `Socia otros ingresos ${stamp}` })).toBeVisible();

  await page.goto('/fees/create');
  await page.getByLabel('Año').fill('2033');
  await page.getByLabel('Importe en euros').fill('10');
  await page.getByRole('button', { name: 'Fijar cuota' }).click();
  await expect(page.getByRole('heading', { name: 'Cuota de 2033' })).toBeVisible();

  const before = await bankFigures(page);

  const csv = ['fecha;importe;concepto', `14/02/2026;15,00;${concept}`, `15/02/2026;-5,00;${charge}`].join('\n');
  await page.goto('/bank');
  await page.getByLabel(uploadLabel).setInputFiles({ name: 'extracto.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.getByRole('button', { name: 'Importar extracto' }).click();
  await expect(page.getByRole('status', { name: 'Resultado de la importación' })).toContainText(/Importados\s*2/);

  const movement = page.locator('article', { has: page.getByRole('heading', { name: concept }) });
  await expect(movement).toContainText('Quedan 15,00 € por conciliar');

  // The description is mandatory: blanks are refused and nothing is classified.
  await movement.getByLabel('Categoría').selectOption('donations');
  await movement.getByLabel('Descripción').fill('   ');
  await movement.getByRole('button', { name: 'Clasificar como otro ingreso' }).click();
  await expect(page.getByRole('alert')).toContainText('Escribe una descripción para el ingreso.');
  await expect(movement).toContainText('Quedan 15,00 € por conciliar');

  await movement.getByLabel('Categoría').selectOption('donations');
  await movement.getByLabel('Descripción').fill(description);
  await movement.getByRole('button', { name: 'Clasificar como otro ingreso' }).click();
  await expect(page.getByRole('status')).toHaveText('Abono clasificado como otro ingreso.');
  await expect(movement).toContainText('Clasificado como otro ingreso · Donaciones');
  await expect(movement).toContainText(description);
  await expect(movement).not.toContainText('por conciliar');

  // The balance is the signed sum of the statement: classifying does not move it, and the credit is
  // no longer pending. It is reported as other income instead.
  const classified = await bankFigures(page);
  expect(classified.balance).toBe(before.balance + 1000);
  expect(classified.unreconciled).toBe(before.unreconciled);
  await expect(page.locator('.kpis > div', { hasText: 'Otros ingresos' })).toContainText('15,00 €');
  await expect(page.getByText('1 ingreso clasificado')).toBeVisible();

  // The void keeps the history and frees the credit again.
  await page.goto('/bank');
  const classifiedMovement = page.locator('article', { has: page.getByRole('heading', { name: concept }) });
  await classifiedMovement.getByLabel('Motivo de la anulación').fill('Era una donación, no una cuota');
  await classifiedMovement.getByRole('button', { name: 'Anular la clasificación' }).click();
  await expect(page.getByRole('status')).toHaveText('Clasificación anulada: el abono vuelve a estar pendiente de conciliación.');
  await expect(classifiedMovement).toContainText('Quedan 15,00 € por conciliar');
  await expect(classifiedMovement).not.toContainText('Clasificado como otro ingreso');

  const freed = await bankFigures(page);
  expect(freed.balance).toBe(classified.balance);
  expect(freed.unreconciled).toBe(before.unreconciled + 1500);

  // A fresh form classifies it again, this time as an activity.
  await page.goto('/bank');
  const freeAgain = page.locator('article', { has: page.getByRole('heading', { name: concept }) });
  await freeAgain.getByLabel('Categoría').selectOption('activities');
  await freeAgain.getByLabel('Descripción').fill(`Concierto benéfico ${stamp}`);
  await freeAgain.getByRole('button', { name: 'Clasificar como otro ingreso' }).click();
  await expect(page.getByRole('status')).toHaveText('Abono clasificado como otro ingreso.');

  // Junta consults the classification and the screen offers it no write action.
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await logIn(page, 'board');
  await page.goto('/bank');
  const seenByBoard = page.locator('article', { has: page.getByRole('heading', { name: concept }) });
  await expect(seenByBoard).toContainText('Clasificado como otro ingreso · Actividades');
  await expect(seenByBoard.getByRole('button', { name: 'Anular la clasificación' })).toHaveCount(0);
  await expect(seenByBoard.getByRole('button', { name: 'Clasificar como otro ingreso' })).toHaveCount(0);
});
