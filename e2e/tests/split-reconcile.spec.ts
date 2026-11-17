import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

const uploadLabel = 'Archivo CSV con las columnas fecha, importe y concepto';

test('tesorería reparte un abono entre dos socios y la suma tiene que cuadrar', async ({ page }) => {
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

  const anaInput = movement.getByLabel(new RegExp(`${ana} · 2031 · pendiente`));
  const luisInput = movement.getByLabel(new RegExp(`${luis} · 2031 · pendiente`));
  // A third pending due stays untouched in every submission: an empty row must not take part.
  await expect(movement.getByLabel(new RegExp(`${sinPagar} · 2031 · pendiente`))).toHaveValue('');

  // A split that does not add up is refused and changes nothing.
  await anaInput.fill('5');
  await luisInput.fill('5');
  await movement.getByRole('button', { name: 'Conciliar el reparto' }).click();
  await expect(page.getByRole('alert')).toContainText('El reparto suma 10,00 €');
  await expect(movement).toContainText('Quedan 20,00 € por conciliar');

  // The split that adds up pays both dues and leaves nothing to reconcile.
  await anaInput.fill('10');
  await luisInput.fill('10');
  await movement.getByRole('button', { name: 'Conciliar el reparto' }).click();
  await expect(page.getByRole('status')).toHaveText('Reparto conciliado desde el extracto.');
  await expect(movement).toContainText(`Conciliado con ${ana}`);
  await expect(movement).toContainText(`Conciliado con ${luis}`);
  await expect(movement).not.toContainText('por conciliar');
});
