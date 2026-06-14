import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Vitest loads this config too. The Laravel plugin guards against running its
// dev server in CI and throws, so it's only included for the real Vite build —
// component tests need just the React + Tailwind transforms.
const isTest = !!process.env.VITEST;

export default defineConfig({
    plugins: [
        ...(isTest
            ? []
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.tsx'],
                      refresh: true,
                      fonts: [
                          bunny('Instrument Sans', {
                              weights: [400, 500, 600],
                          }),
                      ],
                  }),
              ]),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/test/setup.ts'],
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    },
});
