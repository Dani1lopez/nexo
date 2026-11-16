import { expect, test } from '@playwright/test';
import { logIn } from '../support/session';

test('tesorería crea un socio, fija la cuota y registra un pago parcial', async ({ page }) => {
  const name = `Socia E2E ${Date.now()}`;
  await logIn(page, 'treasury');

  await page.goto('/members/create');
  await page.getByLabel('Nombre completo').fill(name);
  await page.getByLabel('Correo ficticio (.test)').fill(`socia.${Date.now()}@example.test`);
  await page.getByLabel('Fecha de alta').fill('2020-01-01');
  await page.getByRole('button', { name: 'Crear socio' }).click();
  await expect(page).toHaveURL(/\/members$/);
  await expect(page.getByRole('heading', { name })).toBeVisible();

  await page.goto('/fees/create');
  await page.getByLabel('Año').fill('2025');
  await page.getByLabel('Importe en euros').fill('30');
  await page.getByRole('button', { name: 'Fijar cuota' }).click();
  await expect(page.getByRole('heading', { name: 'Cuota de 2025' })).toBeVisible();

  const due = page.locator('article', { has: page.getByRole('heading', { name }) });
  await expect(due).toContainText('Pendiente: 30,00 €');

  await due.getByLabel(`Importe del pago en euros (${name})`).fill('12,50');
  await due.getByRole('button', { name: 'Registrar pago' }).click();
  await expect(due).toContainText('Pagado: 12,50 €');
  await expect(due).toContainText('Pendiente: 17,50 €');
  await expect(due).toContainText('Parcial');

  // A payment above what is pending is refused and changes nothing.
  await due.getByLabel(`Importe del pago en euros (${name})`).fill('20');
  await due.getByRole('button', { name: 'Registrar pago' }).click();
  await expect(page.getByRole('alert')).toContainText('El pago supera lo pendiente');
  await expect(due).toContainText('Pendiente: 17,50 €');
});
