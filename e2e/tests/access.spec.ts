import { expect, test } from '@playwright/test';
import { accounts } from '../support/accounts';
import { logIn } from '../support/session';

test.describe('acceso y permisos con sesión real', () => {
  test('un visitante anónimo es enviado a iniciar sesión', async ({ page }) => {
    for (const path of ['/dashboard', '/members', '/fees']) {
      await page.goto(path);
      await expect(page).toHaveURL(/\/login$/);
    }
  });

  test('una contraseña incorrecta no inicia sesión', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(accounts.treasury.email);
    await page.getByLabel('Contraseña').fill('una-contraseña-equivocada');
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('alert')).toBeVisible();
  });

  test('tesorería inicia sesión y puede editar', async ({ page }) => {
    await logIn(page, 'treasury');
    await expect(page.getByText('Tesorería, con edición')).toBeVisible();

    await page.goto('/members');
    await expect(page.getByRole('link', { name: 'Nuevo socio' })).toBeVisible();
    const create = await page.goto('/members/create');
    expect(create?.status()).toBe(200);
  });

  test('junta solo consulta y el cierre de sesión la deja fuera', async ({ page }) => {
    await logIn(page, 'board');
    await expect(page.getByText('Junta, solo consulta')).toBeVisible();

    await page.goto('/members');
    await expect(page.getByRole('heading', { name: 'Socios' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Nuevo socio' })).toHaveCount(0);

    for (const path of ['/members/create', '/fees/create']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(403);
    }

    await page.goto('/dashboard');
    await page.getByRole('button', { name: 'Cerrar sesión' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
  });
});
