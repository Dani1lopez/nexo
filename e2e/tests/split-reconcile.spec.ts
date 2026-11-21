import { expect, test, type Page } from '@playwright/test';
import { logIn } from '../support/session';

const uploadLabel = 'Archivo CSV con las columnas fecha, importe y concepto';

/** Reads the pending figure of the bank block on /saldos, in cents. */
async function pendingCents(page: Page): Promise<number> {
  await page.goto('/saldos');
  const text = await page.locator('.kpis > div', { hasText: 'Sin conciliar' }).innerText();
  const match = text.match(/(\d{1,3}(?:\.\d{3})*,\d{2}) €/);

  return match ? Math.round(Number(match[1].replace(/\./g, '').replace(',', '.')) * 100) : 0;
}

test('tesorería concilia parte de un abono y Banco y Saldos muestran lo que sigue pendiente', async ({ page }) => {
  const stamp = Date.now();
  const ana = `Ana reparto ${stamp}`;
  const luis = `Luis reparto ${stamp}`;
  const sinPagar = `Socia sin pagar ${stamp}`;
  const concept = `Transferencia conjunta ${stamp}`;

  await logIn(page, 'treasury');

  for (const name of [ana, luis, sinPagar]) {
    await page.goto('/members/create');
    await page.getByLabel('Nombre completo').fill(name);
    await page.getByLabel('Correo ficticio (.test)').fill(`${name.replace(/\s+/g, '.')}@example.test`);
    await page.getByLabel('Fecha de alta').fill('2020-01-01');
    await page.getByRole('button', { name: 'Crear socio' }).click();
    await expect(page.getByRole('heading', { name })).toBeVisible();
  }

  await page.goto('/fees/create');
  await page.getByLabel('Año').fill('2031');
  await page.getByLabel('Importe en euros').fill('10');
  await page.getByRole('button', { name: 'Fijar cuota' }).click();
  await expect(page.getByRole('heading', { name: 'Cuota de 2031' })).toBeVisible();

  const csv = ['fecha;importe;concepto', `14/02/2026;20,00;${concept}`].join('\n');
  await page.goto('/bank');
  await page.getByLabel(uploadLabel).setInputFiles({ name: 'extracto.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.getByRole('button', { name: 'Importar extracto' }).click();
  await expect(page.getByRole('status', { name: 'Resultado de la importación' })).toContainText(/Importados\s*1/);

  const movement = page.locator('article', { has: page.getByRole('heading', { name: concept }) });
  await expect(movement).toContainText('Quedan 20,00 € por conciliar');
  await expect(movement.getByText('la suma no puede superar lo que queda por conciliar')).toBeVisible();

  // Everything pending in the whole statement before any split.
  const pendingAfterImport = await pendingCents(page);

  // A split above what is still free is refused and changes nothing, here nor in Saldos.
  await page.goto('/bank');

  const anaInput = movement.getByLabel(new RegExp(`${ana} · 2031 · pendiente`));
  const luisInput = movement.getByLabel(new RegExp(`${luis} · 2031 · pendiente`));
  // A third pending due stays untouched in every submission: an empty row must not take part.
  await expect(movement.getByLabel(new RegExp(`${sinPagar} · 2031 · pendiente`))).toHaveValue('');

  await anaInput.fill('15');
  await luisInput.fill('10');
  await movement.getByRole('button', { name: 'Conciliar el reparto' }).click();
  await expect(page.getByRole('alert')).toContainText('El reparto suma 25,00 €');
  await expect(movement).toContainText('Quedan 20,00 € por conciliar');
  await expect(movement).not.toContainText('Ya se han conciliado');
  expect(await pendingCents(page)).toBe(pendingAfterImport);

  // Less than the credit is now a valid split: it pays the chosen dues and the rest stays pending.
  await page.goto('/bank');
  await anaInput.fill('5');
  await luisInput.fill('5');
  await movement.getByRole('button', { name: 'Conciliar el reparto' }).click();
  await expect(page.getByRole('status')).toHaveText('Reparto conciliado desde el extracto.');
  await expect(movement).toContainText(`Conciliado con ${ana}`);
  await expect(movement).toContainText(`Conciliado con ${luis}`);
  await expect(movement).toContainText('Quedan 10,00 € por conciliar');
  await expect(movement).toContainText('Ya se han conciliado 10,00 € de este abono; el resto sigue pendiente de destino');

  // Saldos drops by exactly the 10,00 € Banco no longer offers for this credit.
  expect(await pendingCents(page)).toBe(pendingAfterImport - 1000);

  // The free remainder is offered again. It closes the credit on the member still unpaid: a due this
  // movement already pays cannot be used twice, so the rest goes to the untouched third due.
  await page.goto('/bank');
  await movement.getByLabel(new RegExp(`${sinPagar} · 2031 · pendiente`)).fill('10');
  await movement.getByRole('button', { name: 'Conciliar el reparto' }).click();
  await expect(page.getByRole('status')).toHaveText('Pago conciliado desde el extracto.');
  await expect(movement).not.toContainText('por conciliar');
  expect(await pendingCents(page)).toBe(pendingAfterImport - 2000);
});
