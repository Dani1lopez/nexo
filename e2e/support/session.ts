import { expect, type Page } from '@playwright/test';
import { accounts } from './accounts';

export async function logIn(page: Page, role: keyof typeof accounts): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Correo electrónico').fill(accounts[role].email);
  await page.getByLabel('Contraseña').fill(accounts[role].password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
}
