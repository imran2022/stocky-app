import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Standalone build for the Client Portal (Vue 3 port of the legacy portal.js).
 * Kept SEPARATE from the admin build so the admin's tuned settings are never at
 * risk. Single self-contained bundle (router dynamic-imports inlined), fixed
 * filenames — portal.blade.php loads /portal-app/app.js + app.css with a
 * filemtime cache-bust.
 */
export default defineConfig({
    plugins: [vue()],
    // Don't copy the project's public/ folder into the build (we build into it).
    publicDir: false,
    build: {
        outDir: resolve(__dirname, 'public/js/portal'),
        emptyOutDir: true,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: resolve(__dirname, 'resources/src/portal/main.js'),
            output: {
                inlineDynamicImports: true,
                entryFileNames: 'app.js',
                assetFileNames: 'app.[ext]',
            },
        },
    },
});
