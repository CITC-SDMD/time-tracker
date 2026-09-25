import tailwindcss from '@tailwindcss/vite'

// Built as a static SPA (`nuxt generate`) and served by the same nginx as the
// Laravel API — same origin as /api/v1, so no CORS and Sanctum's cookie-based
// SPA auth just works (docs/DEVELOPMENT_PLAN.md §2, §9.2).
export default defineNuxtConfig({
  modules: ['@nuxt/eslint'],
  ssr: false,
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    public: {
      // Same-origin in production. Point this at a dev API during local development.
      apiBase: '/api/v1',
    },
  },
  devServer: { port: 3100 },
  compatibilityDate: '2026-09-01',
  // In dev the page is on :3100 and Laravel on :8000. Proxying makes them one origin, as in
  // production, so the Sanctum session cookie and CSRF token work without any CORS setup.
  nitro: {
    devProxy: {
      '/api': { target: 'http://127.0.0.1:8000/api', changeOrigin: true },
      '/auth': { target: 'http://127.0.0.1:8000/auth', changeOrigin: true },
      '/sanctum': { target: 'http://127.0.0.1:8000/sanctum', changeOrigin: true },
    },
  },
  vite: {
    plugins: [tailwindcss()],
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
