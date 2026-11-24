// Progressive enhancement of the guided demo (issue #74, T9 and T10). Only a demo session loads it
// and nothing here is required: the notice is a native <details> and the real form submits anyway.
//
// Two things happen here and nothing else:
//
// 1. The focus of the guide. Closing the notice with the keyboard hands the focus to the real
//    control its step names (V2, H3a) instead of leaving it on the <summary>. Without JavaScript —
//    or with the script blocked — the notice still opens, closes and repeats with mouse and
//    keyboard, and the focus simply stays on the <summary>, which is a real focusable control.
// 2. The live remainder of the real reconcile control (T10): 100,00 → 60,00 → 20,00 on /bank.
//    The server already renders the real sentence «Quedan … por conciliar.» inside an
//    <output aria-live="polite">, the field names and the submission_key of the real form are
//    untouched, and the form submits without JavaScript. With JavaScript, typing an amount in the
//    real inputs updates that same output, so the visitor sees what is left before confirming.
//    Only a demo session loads this entry; the rest of the application keeps its weight budget.

// 1. Focus: the notice says in data-guide-target which real control its step points at.
for (const notice of document.querySelectorAll('details.guide[data-guide-target]')) {
    const summary = notice.querySelector('summary');
    const target = document.querySelector(notice.dataset.guideTarget);

    if (!summary || !target) continue;

    // A pointer click never fires this keydown, so the flag tells a keyboard close from a click.
    let keyboard = false;

    summary.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') keyboard = true;
    });

    notice.addEventListener('toggle', () => {
        const fromKeyboard = keyboard;
        keyboard = false;

        // Opening never moves the focus, and a mouse close leaves it where the visitor put it.
        if (notice.open || !fromKeyboard) return;

        target.focus();
    });
}

// 2. The remainder of the real reconcile form.
const form = document.querySelector('form[action$="/reconcile"]');
const output = form?.querySelector('output[aria-live="polite"]');
const inputs = form ? [...form.querySelectorAll('input[name^="allocations["]')] : [];

/** Reads an amount the same way the real form does: 40, 40,00 or 40.00 euros. */
const parseCents = (raw) => {
    const text = String(raw).trim();

    if (!/^\d{1,6}(?:[.,]\d{1,2})?$/.test(text)) return null;

    const [euros, decimals = ''] = text.split(/[.,]/);

    return Number(euros) * 100 + Number(decimals.padEnd(2, '0'));
};

/** Reads the cents the server rendered in the real sentence, thousands dot included. */
const readCents = (text) => {
    const match = String(text).match(/(\d{1,3}(?:\.\d{3})*,\d{2})/);

    return match ? parseCents(match[1].replace(/\./g, '')) ?? 0 : 0;
};

if (form && output && inputs.length > 0) {
    const money = new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const left = readCents(output.textContent);

    const paint = () => {
        const assigned = inputs.reduce((total, input) => total + (parseCents(input.value) ?? 0), 0);
        const text = `Quedan ${money.format(Math.max(0, left - assigned) / 100)} € por conciliar.`;

        // Setting the same text again would announce it once more for no reason.
        if (output.textContent.trim() !== text) output.textContent = text;
    };

    form.addEventListener('input', paint);
    paint();
}
