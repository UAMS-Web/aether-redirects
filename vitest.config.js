import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { configDefaults, defineConfig } from 'vitest/config';
import statamicStubs from './resources/js/testing/statamic-stubs-plugin.js';

// Standalone Vitest config for the repository's own JavaScript, in two projects: the guards in
// `scripts/` (node) and the Control Panel pages in `resources/js/pages` (jsdom). The add-on's PHP
// tests run under Pest, not here. The page setup follows the `testing-statamic-cp-components`
// skill; use `vue()` here, not the `statamic()` plugin, which rewrites `vue` to `window.Vue`.
const inertiaStub = fileURLToPath(new URL('./tests/js/stubs/inertia.js', import.meta.url));

// `.claude/worktrees/` holds full checkouts of other branches, each carrying its own copy of every
// test file; without the exclude the primary collects them all.
const exclude = [...configDefaults.exclude, '.claude/worktrees/**'];

export default defineConfig({
    test: {
        projects: [
            {
                test: {
                    name: 'scripts',
                    include: ['scripts/**/*.test.mjs'],
                    exclude,
                    environment: 'node',
                },
            },
            {
                plugins: [vue(), statamicStubs({ sources: ['resources/js'] })],
                resolve: {
                    // The stubs plugin resolves `@statamic/cms` and `@statamic/cms/ui` only. The pages
                    // also import `Head` from `@statamic/cms/inertia`, which it does not cover; a change
                    // to the plugin belongs to UAMS-Web/uams-claude-skills.
                    alias: { '@statamic/cms/inertia': inertiaStub },
                },
                test: {
                    name: 'pages',
                    include: ['tests/js/**/*.test.js'],
                    exclude,
                    environment: 'jsdom',
                    setupFiles: ['resources/js/testing/statamic-harness.js'],
                },
            },
        ],
    },
});
