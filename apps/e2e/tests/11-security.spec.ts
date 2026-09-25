import { DASH_HEADERS, as, expect, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

const USER_PAGES = ['/user', '/user/people', '/user/reports', '/user/settings', '/user/audit', '/user/profile', '/user/employees/1']
const OIC_API = ['/admin/settings', '/admin/audit']
const MANAGER_API = ['/employees', '/reports/daily?from=2026-01-01&to=2026-01-07', '/reports/apps?from=2026-01-01&to=2026-01-07', '/reports/team?from=2026-01-01&to=2026-01-07']

test.beforeEach(() => artisan('cache:clear'))

test.describe('signed out', () => {
  for (const path of USER_PAGES) {
    test(`${path} sends a signed-out visitor to sign in`, async ({ page }) => {
      await page.goto(path)
      await expect(page).toHaveURL(/\/$/)
      await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible()
    })
  }

  test('every API route refuses a signed-out caller with 401 and never a 500', async ({ page }) => {
    for (const path of [...OIC_API, ...MANAGER_API, '/me']) {
      const res = await page.request.get(`/api/v1${path}`, { headers: DASH_HEADERS })
      expect(res.status(), path).toBe(401)
      expect((await res.json()).error.code).toBe('UNAUTHENTICATED')
    }
  })

  test('the superadmin area is closed too', async ({ page }) => {
    await page.goto('/superadmin')
    await expect(page).toHaveURL(/\/$/)
  })
})

test.describe('a project manager', () => {
  test.use(as('pm1'))

  test('cannot open the superadmin area, settings or the audit log', async ({ page }) => {
    for (const path of ['/superadmin', '/user/settings', '/user/audit']) {
      await page.goto(path)
      await expect(page).toHaveURL(/\/user$/)
    }
  })

  test('the OIC-only API answers 403', async ({ page }) => {
    for (const path of OIC_API) expect((await page.request.get(`/api/v1${path}`, { headers: DASH_HEADERS })).status(), path).toBe(403)
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const put = await page.request.put('/api/v1/admin/settings', { headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }, data: { idleThresholdSeconds: 60 } })
    expect(put.status()).toBe(403)
  })
})

test.describe('the OIC', () => {
  test.use(as('oic'))

  test('cannot open the superadmin area', async ({ page }) => {
    await page.goto('/superadmin')
    await expect(page).toHaveURL(/\/user$/)
  })

  test('a write without the CSRF token is refused and changes nothing', async ({ page }) => {
    await page.goto('/user')
    const res = await page.request.put('/api/v1/admin/settings', { headers: DASH_HEADERS, data: { idleThresholdSeconds: 60 } })
    expect(res.status()).toBe(419)
    const now = await (await page.request.get('/api/v1/admin/settings', { headers: DASH_HEADERS })).json()
    expect(now.idleThresholdSeconds).toBe(300)
  })

  test('a write from another site\'s origin is not treated as the signed-in dashboard', async ({ page }) => {
    await page.goto('/user')
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const res = await page.request.put('/api/v1/admin/settings', {
      headers: { Accept: 'application/json', Origin: 'https://evil.example', Referer: 'https://evil.example/', 'X-XSRF-TOKEN': xsrf },
      data: { idleThresholdSeconds: 60 },
    })
    expect([401, 419]).toContain(res.status())
  })

  test('the session ending mid-use sends the person to sign in without a crash', async ({ page, context, allow }) => {
    allow(/\/api\/v1\/\w+.* 401/)
    await page.goto('/user')
    await expect(page.getByRole('row').first()).toBeVisible()
    await context.clearCookies()
    await page.getByRole('button', { name: 'Refresh' }).click()
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible()
  })

  test('nothing sensitive is left in the page or its storage', async ({ page }) => {
    await page.goto('/user/profile')
    const html = await page.content()
    expect(html).not.toMatch(/"password"\s*:\s*"/)
    const stored = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }))
    expect(stored).not.toMatch(/token|password|secret/i)
    // the session cookie is not readable by page scripts
    const cookies = await page.context().cookies()
    const session = cookies.find(c => /session/i.test(c.name))
    expect(session?.httpOnly).toBe(true)
  })

  test('a person id in the address that is not a number is not sent to the server as-is', async ({ page }) => {
    const seen: string[] = []
    page.on('request', r => seen.push(r.url()))
    await page.goto('/user/employees/1%20OR%201=1')
    await expect(page.getByText('You cannot view this person.')).toBeVisible()
    expect(seen.some(u => u.includes('OR%201=1') && u.includes('/timeline'))).toBe(false)
  })

  test('script in a name is shown as text, never run', async ({ page, request }) => {
    void request
    await page.goto('/user/people')
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const created = await page.request.post('/api/v1/admin/employees', {
      headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf },
      data: { name: '<img src=x onerror="window.__pwned=1">Mallory', email: 'mallory.e2e@test.com', role: 'project_manager' },
    })
    expect(created.status()).toBe(201)
    await page.reload()
    await expect(page.getByText('<img src=x onerror="window.__pwned=1">Mallory')).toBeVisible()
    expect(await page.evaluate(() => (window as unknown as { __pwned?: number }).__pwned)).toBeUndefined()
    await page.goto('/user/audit')
    await expect(page.locator('td', { hasText: 'Mallory' }).first()).toBeVisible()
    expect(await page.evaluate(() => (window as unknown as { __pwned?: number }).__pwned)).toBeUndefined()
    // clean up: the account was never used
    const list = await (await page.request.get('/api/v1/employees?includeDeactivated=1', { headers: DASH_HEADERS })).json() as { id: string, email: string }[]
    const id = list.find(p => p.email === 'mallory.e2e@test.com')!.id
    expect((await page.request.delete(`/api/v1/admin/employees/${id}`, { headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf } })).status()).toBeLessThan(300)
  })
})
