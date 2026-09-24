import { invoke } from '@tauri-apps/api/core'

// Mirrors `MeDto` in src-tauri/src/api.rs (packages/shared `Me`). The token itself never
// reaches this side: it stays in Windows Credential Manager, owned by Rust.
export interface Me {
  id: string
  name: string
  email: string
  role: string
  status: string
  consentVersion: number | null
  consentRequired: boolean
  officeSettings: {
    timezone: string
    idleThresholdSeconds: number
    windowTitleMode: 'FULL' | 'APP_ONLY'
    minAgentVersion: string
    consentVersion: number
  }
}

export interface LogoutResult {
  synced: boolean
  pendingCount: number
}

const LOGIN_ERRORS: Record<string, string> = {
  WRONG_PASSWORD: 'Wrong email or password.',
  OFFLINE: 'Can\'t reach the server. Check your internet connection and try again.',
  DEACTIVATED: 'Your account is deactivated.',
  UPGRADE_REQUIRED: 'Please update the app.',
  RATE_LIMITED: 'Too many attempts. Wait a minute and try again.',
  BUSY: 'Stop tracking before switching accounts.',
  SERVER_ERROR: 'The server had a problem. Try again in a moment.',
}

export function describeLoginError(error: unknown): string {
  return LOGIN_ERRORS[String(error)] ?? `Something went wrong (${String(error)}).`
}

/** The logged-in user, shared across pages. `undefined` until first loaded. */
export function useAuth() {
  const me = useState<Me | null | undefined>('me', () => undefined)

  async function load() {
    me.value = await invoke<Me | null>('get_session')
    return me.value
  }

  async function login(email: string, password: string) {
    me.value = await invoke<Me>('login', { email, password })
  }

  async function acceptConsent() {
    me.value = await invoke<Me>('accept_consent')
  }

  async function refresh() {
    me.value = await invoke<Me | null>('refresh_me')
  }

  async function logout(): Promise<LogoutResult> {
    const result = await invoke<LogoutResult>('logout')
    me.value = null
    return result
  }

  return { me, load, login, acceptConsent, refresh, logout }
}
