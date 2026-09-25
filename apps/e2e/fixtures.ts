import path from 'node:path'
import { expect, test as base, type Page } from '@playwright/test'

export const PASSWORD = 'password'
export const ACCOUNTS = {
  oic: 'oic@test.com',
  pm1: 'pm1@test.com',
  pm2: 'pm2@test.com',
  tl1: 'tl1@test.com',
  tl2: 'tl2@test.com',
  tl3: 'tl3@test.com',
  dev1: 'dev1@test.com',
  admin: 'admin@test.com',
} as const

export interface Problem {
  kind: 'console' | 'pageerror' | 'http'
  text: string
}

// Every test fails on anything a real person could not see but a developer must: a console error
// or warning (Vue warnings included), an uncaught exception, or an unexpected 4xx/5xx from the
// API. A test that expects a failing call says so with `allow(/regex on "METHOD url status"/)`.
export const test = base.extend<{ problems: Problem[], allow: (pattern: RegExp) => void }>({
  page: async ({ page }, use) => {
    // fonts and images from other sites are not part of what is tested (and may be offline)
    await page.route(url => !['localhost', '127.0.0.1'].includes(url.hostname), route => route.abort())
    await use(page)
  },
  problems: [async ({ page }, use) => {
    const list: Problem[] = []
    page.on('console', (m) => {
      if (m.type() === 'error' || m.type() === 'warning') list.push({ kind: 'console', text: `${m.type()}: ${m.text()}` })
    })
    page.on('pageerror', e => list.push({ kind: 'pageerror', text: e.message }))
    page.on('response', (r) => {
      if (r.status() >= 400 && new URL(r.url()).hostname === 'localhost') {
        list.push({ kind: 'http', text: `${r.request().method()} ${new URL(r.url()).pathname} ${r.status()}` })
      }
    })
    await use(list)
  }, { auto: true }],
  allow: [async ({ problems }, use) => {
    // asking "am I signed in?" while signed out is how every visit starts, so its 401 is normal
    const patterns: RegExp[] = [/^GET \/api\/v1\/me 401$/]
    await use(pattern => patterns.push(pattern))
    const bad = problems.filter(p => !patterns.some(re => re.test(p.text)))
    // the browser logs "Failed to load resource" for every blocked or failing request: the http
    // entry already says which one, so that duplicate line is dropped
    const real = bad.filter(p => !(p.kind === 'console' && /Failed to load resource|Couldn't load preload assets/.test(p.text)))
    expect(real, `problems seen by the browser:\n${real.map(p => `  [${p.kind}] ${p.text}`).join('\n')}`).toEqual([])
  }, { auto: true }],
})

export { expect }

/** Signs in through the real form and waits for the landing page. */
export async function loginAs(page: Page, email: string, password = PASSWORD): Promise<void> {
  await page.goto('/')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL(/\/(user|superadmin)/)
}

/** The saved session of a demo account (made once in global-setup): `test.use(as('oic'))`. */
export function as(key: Exclude<keyof typeof ACCOUNTS, 'dev1'>) {
  return { storageState: path.resolve(__dirname, '.auth', `${key}.json`) }
}

/** The id of a person, looked up through the API as whoever the page is signed in as. */
export async function idOf(page: Page, email: string): Promise<string> {
  const res = await apiGet(page, '/employees?includeDeactivated=1')
  const list = await res.json() as { id: string, email: string }[]
  const found = list.find(p => p.email === email)
  if (!found) throw new Error(`${email} is not visible to this account`)
  return String(found.id)
}

/** Sanctum treats a call as coming from the dashboard (and so uses the session cookie) only when it carries the dashboard's origin. */
export const DASH_HEADERS = { Accept: 'application/json', Origin: 'http://localhost:3101', Referer: 'http://localhost:3101/' }

/** A GET to the API as whoever the page is signed in as. */
export async function apiGet(page: Page, path: string) {
  return page.request.get(`/api/v1${path}`, { headers: DASH_HEADERS })
}

/** A second, signed-out browser window (another person) with the same outside-world blocking. */
export async function otherPerson(browser: import('@playwright/test').Browser, key?: Exclude<keyof typeof ACCOUNTS, 'dev1'>): Promise<Page> {
  const context = await browser.newContext({ baseURL: 'http://localhost:3101', // a signed-out window unless a saved session is asked for (a plain newContext would inherit the test's own)
    storageState: key ? as(key).storageState : { cookies: [], origins: [] } })
  await context.route(url => !['localhost', '127.0.0.1'].includes(url.hostname), route => route.abort())
  return context.newPage()
}
