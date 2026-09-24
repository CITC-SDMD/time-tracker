import tailwindcss from '@tailwindcss/vite'

// Built as a static SPA (`nuxt generate`) and served by the same nginx as the
// Laravel API — same origin as /api/v1, so no CORS and Sanctum's cookie-based
// SPA auth just works (docs/DEVELOPMENT_PLAN.md §2, §9.2).
export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  ssr: false,
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  typescript: { strict: true },
  devServer: { port: 3100 },
  runtimeConfig: {
    public: {
      // Same-origin in production. Point this at a dev API during local development.
      apiBase: '/api/v1',
    },
  },
  vite: {
    plugins: [tailwindcss()],
  },
})
