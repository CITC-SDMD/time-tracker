import tailwindcss from '@tailwindcss/vite'

// Runs inside Tauri: no server rendering, static files only.
export default defineNuxtConfig({
  modules: ['@nuxt/eslint'],
  ssr: false,
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  // Rust code must not trigger Nuxt reloads.
  ignore: ['**/src-tauri/**'],
  devServer: { port: 3000 },
  compatibilityDate: '2026-09-01',
  vite: {
    plugins: [tailwindcss()],
    clearScreen: false,
    envPrefix: ['VITE_', 'TAURI_'],
    server: { strictPort: true },
  },
  typescript: { strict: true },
  eslint: {
    config: {
      // Lint-as-format (single quotes, no semicolons, 2-space indent) instead of a
      // separate Prettier — one tool, no config drift between the two.
      stylistic: true,
    },
  },
})
