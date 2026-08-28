import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Nothing in this app ever adds a `dark` class, so with the default
    // 'media' strategy every `dark:` utility was silently following the
    // visitor's OS/browser color-scheme preference instead of the shop's own
    // chosen theme (or, on the landing/auth pages, the app's fixed light
    // look) — causing things like dark:text-white firing on top of a card a
    // theme deliberately kept light, making the text invisible. 'class'
    // makes every `dark:` utility permanently inert app-wide without having
    // to hunt down and strip dark: classes from every file that has them.
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            // Native system font stack — no external font CDN dependency (fonts.bunny.net
            // is unreachable from some environments this app runs in, causing a slow
            // failed request + fallback instead of just rendering immediately).
            fontFamily: {
                sans: defaultTheme.fontFamily.sans,
            },
            // "Amber walnut morning" — the landing page's brand palette.
            colors: {
                walnut: {
                    50: '#EBEFEE',
                    200: '#CCB499',
                    400: '#C8906D',
                    600: '#BB6C43',
                    900: '#4A413C',
                },
            },
        },
    },

    plugins: [forms],
};
