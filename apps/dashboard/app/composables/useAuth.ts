import type { Me, TwoFactorChallenge } from 'shared'

// Sanctum SPA authentication: a session cookie + CSRF token, not a bearer token
// (docs/DEVELOPMENT_PLAN.md §9.2). Everyone with an account may sign in; what they then see follows the permissions
// of their role (useAccess), and the server checks them again on every route.
export function useAuth() {
  const { web } = useApi()
  const me = useState<Me | null>('auth:me', () => null)

  const isSuperadmin = computed(() => !!me.value?.isSuperadmin)

  /** Signs in; a person with two-factor sign-in gets a challenge back instead (answer it with `verifyTwoFactor`). */
  async function login(email: string, password: string): Promise<Me | TwoFactorChallenge> {
    await web('/sanctum/csrf-cookie')
    const answer = await web<Me | TwoFactorChallenge>('/auth/login', { method: 'POST', body: { email, password } })
    if ('twoFactorRequired' in answer)
      return answer
    me.value = answer
    return answer
  }

  async function verifyTwoFactor(challenge: string, code: string) {
    const signedIn = await web<Me>('/auth/two-factor', { method: 'POST', body: { challenge, code } })
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

  return { me, isSuperadmin, login, verifyTwoFactor, logout, restore }
}
