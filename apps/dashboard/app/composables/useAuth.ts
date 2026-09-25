import { isManagerRole, type Me } from 'shared'

// Sanctum SPA authentication: a session cookie + CSRF token, not a bearer token
// (docs/DEVELOPMENT_PLAN.md §9.2). Only managers (OIC, Project Manager, Team Leader) may
// use the dashboard; the server refuses everyone else at login and on every route.
export function useAuth() {
  const { web } = useApi()
  const me = useState<Me | null>('auth:me', () => null)

  const isManager = computed(() => !!me.value && isManagerRole(me.value.role))
  const isOic = computed(() => me.value?.role === 'OIC')

  async function login(email: string, password: string) {
    await web('/sanctum/csrf-cookie')
    me.value = await web<Me>('/auth/login', { method: 'POST', body: { email, password } })
  }

  async function logout() {
    try {
      await web('/auth/logout', { method: 'POST' })
    }
    finally {
      me.value = null
    }
    await navigateTo('/login')
  }

  /** Picks up an existing session cookie (a page reload); leaves `me` empty if there is none. */
  async function restore() {
    try {
      me.value = await web<Me>('/api/v1/me')
    }
    catch {
      me.value = null
    }
  }

  return { me, isManager, isOic, login, logout, restore }
}
