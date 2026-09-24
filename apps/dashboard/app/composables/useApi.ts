// Thin wrapper around Laravel's JSON API under /api/v1 (docs/DEVELOPMENT_PLAN.md §10).
// Same-origin in production, so cookies ride along automatically; `credentials: 'include'`
// keeps that working in dev too, when apiBase points at a separate dev server.
export function useApi() {
  const config = useRuntimeConfig()

  function api<T>(path: string, options: Parameters<typeof $fetch<T>>[1] = {}) {
    return $fetch<T>(`${config.public.apiBase}${path}`, {
      credentials: 'include',
      ...options,
    })
  }

  return { api }
}
