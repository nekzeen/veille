import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Harmonisation M8.5 : fond de page, bandeaux, accent.
                pagebg: '#E2E6EC',
                surface: '#DCEBFA',
                accent: '#4F46E5',
                chip: '#F1F3F8',
            },
        },
    },

    plugins: [forms],
};
