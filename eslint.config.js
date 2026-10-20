import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/ssr/**', 'resources/js/actions/**', 'resources/js/routes/**', 'resources/js/wayfinder/**'] },
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: { globals: globals.browser },
        plugins: { 'react-hooks': reactHooks },
        // The classic hooks rules only: this app does not use the React Compiler, whose rules
        // (set-state-in-effect, purity, immutability) flag patterns that are fine without it.
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'error',
        },
    },
);
