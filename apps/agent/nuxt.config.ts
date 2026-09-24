import tailwindcss from '@tailwindcss/vite'

// Runs inside Tauri: no server rendering, static files only.
export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  ssr: false,
  devtools: { enabled: false },
  css: ['~/assets/css/main.css'],
  typescript: { strict: true },
  // Rust code must not trigger Nuxt reloads.
  ignore: ['**/src-tauri/**'],
  devServer: { port: 3000 },
  vite: {
    plugins: [tailwindcss()],
    clearScreen: false,
    envPrefix: ['VITE_', 'TAURI_'],
    server: { strictPort: true },
  },
})
