import type { Me } from 'shared'

// Sanctum SPA authentication: a session cookie + CSRF token, not a bearer token
// (docs/DEVELOPMENT_PLAN.md §9.2) — that's what makes /sanctum/csrf-cookie, /login and
// /logout Laravel's own unprefixed web routes, unlike everything else under /api/v1.
// The role check ("admins only", sign out + redirect) and the route guard land in
// Phase 2, once apps/api actually exists to talk to.
export function useAuth() {
  const { api } = useApi()
  const me = useState<Me | null>('auth:me', () => null)

  async function login(email: string, password: string) {
    await $fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    await $fetch('/login', { method: 'POST', credentials: 'include', body: { email, password } })
    me.value = await api<Me>('/me')
  }

  async function logout() {
    await $fetch('/logout', { method: 'POST', credentials: 'include' })
    me.value = null
  }

  return { me, login, logout }
}
