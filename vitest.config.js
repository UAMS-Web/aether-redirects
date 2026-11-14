import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { configDefaults, defineConfig } from 'vitest/config';

// Standalone Vitest config for the repository's own JavaScript: the guards in `scripts/` and the
// Control Panel pages in `resources/js/pages`. The add-on's PHP tests run under Pest, not here.
const stub = (name) => fileURLToPath(new URL(`./tests/js/stubs/${name}.js`, import.meta.url));

export default defineConfig({
    plugins: [vue()],
    resolve: {
        // The pages import Statamic's Control Panel components, which need the booted Control Panel.
        // The tests mount a page with these stand-ins instead.
        alias: {
            '@statamic/cms/inertia': stub('inertia'),
            '@statamic/cms/ui': stub('ui'),
        },
    },
    test: {
        // `.claude/worktrees/` holds full checkouts of other branches, each carrying its own copy
        // of every test file; without the exclude the primary collects them all.
        include: ['scripts/**/*.test.mjs', 'tests/js/**/*.test.js'],
        exclude: [...configDefaults.exclude, '.claude/worktrees/**'],
        environment: 'node',
    },
});
