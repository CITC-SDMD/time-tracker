import type { Me } from 'shared'

// Sanctum SPA authentication: a session cookie + CSRF token, not a bearer token
// (docs/DEVELOPMENT_PLAN.md §9.2). Everyone with an account may sign in; what they then see follows the permissions
// of their role (useAccess), and the server checks them again on every route.
export function useAuth() {
  const { web } = useApi()
  const me = useState<Me | null>('auth:me', () => null)

  const isSuperadmin = computed(() => !!me.value?.isSuperadmin)

  async function login(email: string, password: string) {
    await web('/sanctum/csrf-cookie')
    const signedIn = await web<Me>('/auth/login', { method: 'POST', body: { email, password } })
    me.value = signedIn
    return signedIn
  }

  async function logout() {
    try {
      await web('/auth/logout', { method: 'POST' })
    }
    finally {
      me.value = null
    }
    await navigateTo('/')
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

  return { me, isSuperadmin, login, logout, restore }
}
