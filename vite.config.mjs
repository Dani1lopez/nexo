import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// `resources/js/landing.js` is the only entry that loads Three.js: the landing route is the
// only Blade view that includes it (issue #60). The rest of the application keeps
// `resources/js/theme.js` and its weight budget untouched.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/theme.js',
                'resources/css/landing.css',
                'resources/js/landing.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
