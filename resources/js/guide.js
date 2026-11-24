// The live remainder of the guided demo (issue #74, T10): 100,00 → 60,00 → 20,00 on /bank.
//
// The server already renders the real sentence «Quedan … por conciliar.» inside an
// <output aria-live="polite">, the field names and the submission_key of the real form are
// untouched, and the form submits without JavaScript. With JavaScript, typing an amount in the
// real inputs updates that same output, so the visitor sees what is left before confirming.
// Only a demo session loads this entry; the rest of the application keeps its weight budget.
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
