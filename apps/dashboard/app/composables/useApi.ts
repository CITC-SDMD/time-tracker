import type { Me } from 'shared'

type FetchOptions = NonNullable<Parameters<typeof $fetch>[1]>

function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
  return match?.[1] ? decodeURIComponent(match[1]) : undefined
}

/** The person is signed out (or their account was deactivated): a fresh login is needed. */
function isSignedOut(error: unknown): boolean {
  const e = error as { statusCode?: number, data?: { error?: { code?: string } } }
  return e.statusCode === 401 || e.data?.error?.code === 'ACCOUNT_DEACTIVATED' || e.data?.error?.code === 'ORG_SUSPENDED'
}

/** The HTTP status of a failed call, if there was one. */
export function statusOf(error: unknown): number | undefined {
  return (error as { statusCode?: number }).statusCode
}

/** The server's own message ("Incorrect email or password."), or `fallback`. */
export function messageOf(error: unknown, fallback: string): string {
  const data = (error as { data?: { error?: { message?: string } } }).data
  return data?.error?.message ?? fallback
}

// Thin wrapper around Laravel (docs/DEVELOPMENT_PLAN.md §10). Same origin in production; in
// dev, nuxt.config proxies /api, /auth and /sanctum to the PHP server, so cookies just work.
// Sanctum's SPA login needs the XSRF cookie echoed back in a header on every write.
// The paths of what happens inside an organization: when a superadmin has an office open they go through
// /platform/organizations/{id}/office instead (useOffice).
const ORGANIZATION_PATH = /^\/(employees|reports|admin|roles|permissions|screenshots)(\/|$)/

export function useApi() {
  const config = useRuntimeConfig()
  const office = useOffice()
  const me = useState<Me | null>('auth:me', () => null)

  async function request<T>(url: string, options: FetchOptions, signOutOnAuthError: boolean): Promise<T> {
    const token = xsrfToken()
    try {
      return await $fetch<T>(url, {
        credentials: 'include',
        ...options,
        headers: {
          Accept: 'application/json',
          ...(token ? { 'X-XSRF-TOKEN': token } : {}),
          ...(options.headers as Record<string, string> | undefined),
        },
      } as never) as T
    }
    catch (error) {
      // A deactivated manager gets 403 ACCOUNT_DEACTIVATED on the next request (Test 6.9).
      if (signOutOnAuthError && isSignedOut(error)) {
        me.value = null
        await navigateTo('/')
      }
      throw error
    }
  }

  /** The address of an API path, inside the open office when there is one (also for pictures in <img>). */
  function url(path: string): string {
    return `${config.public.apiBase}${office.inOffice.value && ORGANIZATION_PATH.test(path) ? office.prefix.value : ''}${path}`
  }

  /** A call under /api/v1. Signs the person out on 401 or a deactivated account. */
  function api<T>(path: string, options: FetchOptions = {}) {
    return request<T>(url(path), options, true)
  }

  /** A call to one of Laravel's own unprefixed routes (/auth/login, /sanctum/csrf-cookie). */
  function web<T>(path: string, options: FetchOptions = {}) {
    return request<T>(path, options, false)
  }

  return { api, web, url }
}
