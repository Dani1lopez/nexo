import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * Issue #74, T9, T10 and T12 (S16–S19, S22 V1–V7): the guided demo walks the real screens and the
 * guide is an in-place notice on them. Nothing here uses a walkthrough route: the visitor enters
 * with «Probar sin cuenta», navigates with the real menu and leaves with «Salir de la demo».
 */

const consoleErrors = (page: Page): string[] => {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(error.message));

  return errors;
};

/** The notice of one step, as the real screen renders it. */
const guide = (page: Page, step: 'panel' | 'bank' | 'saldos'): Locator => page.locator(`[data-guide="${step}"]`);

/** Home → access → demo session, all on the real URLs. */
const enterDemo = async (page: Page): Promise<void> => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Entrar a la demo' }).first().click();
  await expect(page).toHaveURL(/\/login$/);

  await page.getByRole('button', { name: 'Probar sin cuenta' }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
};

/** S19 and V2: no dialog, no scroll lock, no meta refresh, one notice per screen. */
const expectNoDialog = async (page: Page): Promise<void> => {
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('[aria-modal="true"]')).toHaveCount(0);
  await expect(page.locator('meta[http-equiv="refresh"]')).toHaveCount(0);
  await expect(page.locator('[data-guide]')).toHaveCount(1);

  const overflow = await page.locator('body').evaluate((body) => getComputedStyle(body).overflow);

  expect(overflow).not.toBe('hidden');
};

/** S19 and V6: it closes with the mouse and with the keyboard, and it repeats on the same URL. */
const expectClosable = async (page: Page, step: 'panel' | 'bank' | 'saldos'): Promise<void> => {
  const notice = guide(page, step);
  const url = page.url();

  await expect(notice.locator('.guide-copy')).toBeVisible();
  await expect(notice.locator('.guide-close')).toBeVisible();
  await expect(notice.locator('.guide-repeat')).toBeHidden();

  // Mouse: closing never navigates and the real control stays where it was.
  await notice.locator('summary').click();
  await expect(notice.locator('.guide-copy')).toBeHidden();
  await expect(notice.locator('.guide-repeat')).toBeVisible();
  expect(page.url()).toBe(url);

  // «Ver la guía otra vez» shows it again on the same screen.
  await notice.locator('summary').click();
  await expect(notice.locator('.guide-copy')).toBeVisible();
  expect(page.url()).toBe(url);

  // Keyboard: the toggle is focusable and Enter closes and reopens it, without navigating.
  await notice.locator('summary').focus();
  await expect(notice.locator('summary')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(notice.locator('.guide-copy')).toBeHidden();
  expect(page.url()).toBe(url);

  await page.keyboard.press('Enter');
  await expect(notice.locator('.guide-copy')).toBeVisible();
  expect(page.url()).toBe(url);

  // The focus was never lost: the visible toggle still holds it.
  expect(await page.evaluate(() => document.activeElement?.tagName)).toBe('SUMMARY');
};

/** V7: no horizontal scrolling at a given width. */
const expectNoOverflow = async (page: Page, label: string): Promise<void> => {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );

  expect(overflow, `${label} no desborda en horizontal`).toBeLessThanOrEqual(0);
};

/** V7: the point in the middle of the control belongs to the control, not to the notice. */
const expectTopMost = async (control: Locator, label: string): Promise<void> => {
  await control.scrollIntoViewIfNeeded();

  const owner = await control.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);

    if (!hit) return 'nada';
    if (hit === element || element.contains(hit)) return 'el control';

    return `${hit.tagName}.${typeof hit.className === 'string' ? hit.className : ''}`;
  });

  expect(owner, `${label} es clicable: el aviso no lo tapa`).toBe('el control');
};

/** V7: touch targets of at least 44 px. */
const expectTouchTargets = async (page: Page, step: 'panel' | 'bank' | 'saldos'): Promise<void> => {
  const targets = [guide(page, step).locator('summary'), page.getByRole('button', { name: 'Salir de la demo' })];

  for (const target of targets) {
    const box = await target.boundingBox();

    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
};

test.describe('demo guiada sobre las pantallas reales', () => {
  test('recorre Panel, Movimientos y Saldos con la guía y sale al inicio', async ({ page }) => {
    const errors = consoleErrors(page);

    await enterDemo(page);

    // V1: the walkthrough starts on the real panel, at its real URL.
    await expect(page.getByRole('heading', { name: /^Panel/ })).toBeVisible();

    // Step 1, anchored to the real link to the next screen.
    const panel = guide(page, 'panel');
    await expect(panel).toBeVisible();
    await expect(panel).toContainText('Estás en el panel de ejemplo, con un abono de 100,00 €.');
    await expect(panel).toContainText('Abre «Movimientos bancarios».');
    await expect(panel).toContainText('Sabrás que avanzas cuando veas ese abono.');
    await expectNoDialog(page);
    await expectClosable(page, 'panel');
    await expect(page.getByRole('link', { name: /Movimientos bancarios/ })).toBeVisible();

    // The visitor navigates with the real menu: there is no interstitial screen.
    await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Movimientos', exact: true }).click();
    await expect(page).toHaveURL(/\/bank$/);
    await expect(page.getByRole('heading', { name: 'Movimientos bancarios' })).toBeVisible();
    await expect(page.getByText('TRANSFERENCIA DE EJEMPLO', { exact: true })).toBeVisible();

    // Step 2, in the block of the real control and naming it.
    const bank = guide(page, 'bank');
    await expect(bank).toBeVisible();
    await expect(bank).toContainText('pulsa «Conciliar el reparto»');
    await expectNoDialog(page);
    await expectClosable(page, 'bank');

    // V5: one real reconcile form, with its real action and a single submission_key.
    const form = page.locator('form[action$="/reconcile"]');
    await expect(form).toHaveCount(1);
    await expect(form.locator('input[name="submission_key"]')).toHaveCount(1);
    const action = await form.getAttribute('action');
    expect(action).toMatch(/^\/bank\/movements\/\d+\/reconcile$/);

    // The notice is in the same block as the real control and never covers it.
    await expect(page.locator('article.movement', { has: form })).toContainText('Conciliar el reparto');
    await expectTopMost(page.getByRole('button', { name: 'Conciliar el reparto' }), 'Conciliar el reparto');

    // T10: the real remainder 100,00 → 60,00 → 20,00 in the polite output, fields untouched.
    const remainder = form.locator('output[aria-live="polite"]');
    await expect(remainder).toHaveText('Quedan 100,00 € por conciliar.');

    await form.getByLabel(/Socia Ejemplo A/).fill('40,00');
    await expect(remainder).toHaveText('Quedan 60,00 € por conciliar.');

    await form.getByLabel(/Socio Ejemplo B/).fill('40,00');
    await expect(remainder).toHaveText('Quedan 20,00 € por conciliar.');

    // Confirming keeps the real redirect and the real status of the real form.
    await page.getByRole('button', { name: 'Conciliar el reparto' }).click();
    await expect(page).toHaveURL(/\/bank$/);
    await expect(page.locator('.alert.status')).toHaveText('Reparto conciliado desde el extracto.');
    await expect(page.getByText('Conciliado con Socia Ejemplo A · ', { exact: false })).toBeVisible();

    // Step 3, next to the real bank figures of the same screen.
    await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Saldos', exact: true }).click();
    await expect(page).toHaveURL(/\/saldos$/);

    const saldos = guide(page, 'saldos');
    await expect(saldos).toBeVisible();
    await expect(saldos).toContainText('Compara «Sin conciliar» con «Por cobrar».');
    await expectNoDialog(page);
    await expectClosable(page, 'saldos');

    await expect(page.locator('.kpis > div', { hasText: 'Saldo en el banco' })).toContainText('100,00 €');
    await expect(page.locator('.kpis > div', { hasText: 'Sin conciliar' })).toContainText('20,00 €');
    await expect(page.locator('.kpis > div', { hasText: 'Sin conciliar' })).toContainText('1 abono sin conciliar');
    await expect(page.locator('.entry', { hasText: 'Cuota de' })).toContainText('0,00 €');

    // V9: leaving the demo returns a guest to the home page and the real guards close again.
    await page.getByRole('button', { name: 'Salir de la demo' }).click();
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/bank');
    await expect(page).toHaveURL(/\/login$/);

    expect(errors).toEqual([]);
  });
});

test.describe('demo guiada sin JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('lee y cierra las tres guías y concilia sin ningún script', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Entrar a la demo' }).first().click();
    await page.getByRole('button', { name: 'Probar sin cuenta' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    // S19: without JavaScript the notice is shown whole and closes the same way.
    const panel = guide(page, 'panel');
    await expect(panel.locator('.guide-copy')).toBeVisible();
    await panel.locator('summary').click();
    await expect(panel.locator('.guide-copy')).toBeHidden();
    await expect(panel.locator('.guide-repeat')).toBeVisible();
    await panel.locator('summary').click();
    await expect(panel.locator('.guide-copy')).toBeVisible();

    await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Movimientos', exact: true }).click();
    await expect(page).toHaveURL(/\/bank$/);
    await expect(guide(page, 'bank').locator('.guide-copy')).toBeVisible();

    // The server phrase is what is left and the real form submits with the real action.
    await expect(page.locator('output[aria-live="polite"]')).toHaveText('Quedan 100,00 € por conciliar.');
    await page.getByLabel(/Socia Ejemplo A/).fill('40,00');
    await page.getByLabel(/Socio Ejemplo B/).fill('40,00');
    await expect(page.locator('output[aria-live="polite"]')).toHaveText('Quedan 100,00 € por conciliar.');

    await page.getByRole('button', { name: 'Conciliar el reparto' }).click();
    await expect(page).toHaveURL(/\/bank$/);
    await expect(page.locator('.alert.status')).toHaveText('Reparto conciliado desde el extracto.');

    await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: 'Saldos', exact: true }).click();
    await expect(page).toHaveURL(/\/saldos$/);

    const saldos = guide(page, 'saldos');
    await expect(saldos.locator('.guide-copy')).toBeVisible();
    await saldos.locator('summary').click();
    await expect(saldos.locator('.guide-repeat')).toBeVisible();
    await expect(page.locator('.kpis > div', { hasText: 'Sin conciliar' })).toContainText('20,00 €');

    await page.getByRole('button', { name: 'Salir de la demo' }).click();
    await expect(page).toHaveURL(/\/$/);
  });
});

test.describe('demo guiada en pantallas estrechas', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('no desborda, deja clicable el control real y ofrece objetivos táctiles', async ({ page }) => {
    await enterDemo(page);

    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });

      // Step 1 on the panel: the real link to Movimientos stays clickable.
      await page.goto('/dashboard');
      await expect(guide(page, 'panel')).toBeVisible();
      await expectNoOverflow(page, `/dashboard a ${width} px`);
      await expectTopMost(page.getByRole('link', { name: /Movimientos bancarios/ }), 'Movimientos bancarios');
      await expectTouchTargets(page, 'panel');

      // Step 2 on the bank: the real control of the split is on top and on time.
      await page.goto('/bank');
      await expect(guide(page, 'bank')).toBeVisible();
      await expectNoOverflow(page, `/bank a ${width} px`);
      await expectTopMost(page.getByRole('button', { name: 'Conciliar el reparto' }), 'Conciliar el reparto');
      await expectTouchTargets(page, 'bank');

      const split = await page.getByRole('button', { name: 'Conciliar el reparto' }).boundingBox();
      expect(split?.height ?? 0).toBeGreaterThanOrEqual(44);

      // Step 3 on the balances: the real link back to Movimientos is clickable.
      await page.goto('/saldos');
      await expect(guide(page, 'saldos')).toBeVisible();
      await expectNoOverflow(page, `/saldos a ${width} px`);
      await expectTopMost(page.locator('main').getByRole('link', { name: 'Movimientos', exact: true }), 'Movimientos');
      await expectTouchTargets(page, 'saldos');
    }
  });
});
