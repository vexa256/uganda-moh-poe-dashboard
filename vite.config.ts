/// <reference types="vitest" />

import legacy from '@vitejs/plugin-legacy'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { defineConfig } from 'vite'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    legacy()
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    // tests/unit/rbac.test.cjs is a standalone Node script (calls
    // process.exit on completion). Picked up by vitest's default glob it
    // aborts the run with "Unexpected Exit"; run it separately via
    // `node tests/unit/rbac.test.cjs` instead.
    exclude: [
      '**/node_modules/**',
      '**/dist/**',
      '**/.{idea,git,cache,output,temp}/**',
      '**/{karma,rollup,webpack,vite,vitest,jest,ava,babel,nyc,cypress,tsup,build}.config.*',
      'tests/unit/rbac.test.cjs',
      'tests/e2e/**',
      'tests/integration/**',
      'tests/ui-walkthrough.mjs',
    ],
  }
})
