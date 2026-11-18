// Theme: follows the system until the person chooses; the choice is remembered in this browser.
// Kept in the Vite bundle so the strict Content-Security-Policy stays without inline scripts.
try {
    const saved = localStorage.getItem('nexo-theme');

    if (saved === 'light' || saved === 'dark') {
        document.documentElement.dataset.theme = saved;
    }
} catch (error) {}

const button = document.getElementById('theme-toggle');

if (button) {
    const root = document.documentElement;

    const current = () =>
        root.dataset.theme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');

    const paint = () => {
        button.textContent = current() === 'light' ? 'Modo oscuro' : 'Modo claro';
        button.hidden = false;
    };

    button.addEventListener('click', () => {
        const next = current() === 'light' ? 'dark' : 'light';
        root.dataset.theme = next;

        try {
            localStorage.setItem('nexo-theme', next);
        } catch (error) {}

        paint();
    });

    paint();
}
