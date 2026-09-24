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
