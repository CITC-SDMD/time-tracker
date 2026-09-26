import { ACCOUNTS, DASH_HEADERS, as, expect, idOf, otherPerson, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

// Two organizations share one database (docs §9.5). The demo office has the whole cast and screenshots; "Other Office"
// has an admin (Bea), a lead (Lena) and two staff. Nobody of one may ever see or touch the other: another
// organization's ids are simply "not found" on every route, in the pages and in the API.
test.beforeEach(() => artisan('cache:clear'))

const OTHER_OFFICE = ['Bea Admin', 'Lena Lead', 'Ben Staff', 'Bianca Staff']
const DEMO_NAMES = ['Tina Cruz', 'Dan Ramos', 'Paula Reyes', 'Pedro Santos', 'Tess Lim']

async function demoIds(browser: import('@playwright/test').Browser) {
  const demo = await otherPerson(browser, 'oic')
  await demo.goto('/user')
  const dev = await idOf(demo, 'dev1@test.com')
  const tina = await idOf(demo, 'tl1@test.com')
  const pictures = await (await demo.request.get(`/api/v1/employees/${dev}/screenshots?day=${new Date().toISOString().slice(0, 10)}`, { headers: DASH_HEADERS })).json() as { id: string }[]
  const roles = await (await demo.request.get('/api/v1/roles', { headers: DASH_HEADERS })).json() as { id: string, name: string }[]
  await demo.context().close()
  return { dev, tina, picture: pictures[0]?.id, role: roles.find(r => r.name === 'Team Leader')!.id }
}

test.describe('the admin of the other organization', () => {
  test.use(as('adminB'))

  test('sees only their own people, on every page that lists people', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(OTHER_OFFICE.length + 1)
    for (const name of OTHER_OFFICE) await expect(page.getByRole('row').filter({ hasText: name }).first()).toBeVisible()
    for (const name of DEMO_NAMES) await expect(page.getByText(name)).toHaveCount(0)

    await page.goto('/user/people')
    await expect(page.getByRole('row')).toHaveCount(OTHER_OFFICE.length + 1)
    for (const name of DEMO_NAMES) await expect(page.getByText(name)).toHaveCount(0)

    await page.goto('/user/reports')
    await page.getByRole('tab', { name: 'Team' }).click()
    await expect(page.getByRole('row').filter({ hasText: 'Ben Staff' })).toBeVisible()
    for (const name of DEMO_NAMES) await expect(page.getByText(name)).toHaveCount(0)
  })

  test('has its own roles, settings and audit log', async ({ page }) => {
    await page.goto('/user/roles')
    await expect(page.getByRole('row').filter({ hasText: 'Staff' }).first()).toBeVisible()
    await expect(page.getByText('Team Leader', { exact: true })).toHaveCount(0)
    await page.goto('/user/settings')
    await expect(page.getByLabel('Organization timezone')).toHaveValue('Asia/Manila')
    await page.goto('/user/audit')
    // what the demo office did (added, moved, deactivated people) is not in this log
    await expect(page.getByText('Tina Cruz')).toHaveCount(0)
    await expect(page.getByText('Quentin Estrada')).toHaveCount(0)
  })

  test('a person of the demo office is not found, by address or by the API', async ({ page, browser, allow }) => {
    allow(/\/api\/v1\/.* (403|404)/)
    const ids = await demoIds(browser)
    // the page says so and never shows the person
    await page.goto(`/user/employees/${ids.dev}`)
    await expect(page.getByText('You cannot view this person.')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Dan Ramos' })).toHaveCount(0)

    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const write = { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }
    const day = new Date().toISOString().slice(0, 10)
    for (const path of [
      `/employees/${ids.dev}/summary?from=${day}&to=${day}`,
      `/employees/${ids.dev}/timeline?day=${day}`,
      `/employees/${ids.dev}/screenshots?day=${day}`,
      `/reports/daily?from=${day}&to=${day}&uid=${ids.dev}`,
    ]) expect((await page.request.get(`/api/v1${path}`, { headers: DASH_HEADERS })).status(), path).toBe(404)

    if (ids.picture) {
      expect((await page.request.get(`/api/v1/screenshots/${ids.picture}/thumb`, { headers: DASH_HEADERS })).status()).toBe(404)
      expect((await page.request.get(`/api/v1/screenshots/${ids.picture}/image`, { headers: DASH_HEADERS })).status()).toBe(404)
    }

    // changing, inviting, deleting them, or their roles, are all "not found"
    expect((await page.request.patch(`/api/v1/admin/employees/${ids.dev}`, { headers: write, data: { status: 'inactive' } })).status()).toBe(404)
    expect((await page.request.post(`/api/v1/admin/employees/${ids.dev}/resend-invite`, { headers: write })).status()).toBe(404)
    expect((await page.request.delete(`/api/v1/admin/employees/${ids.dev}`, { headers: write })).status()).toBe(404)
    expect((await page.request.patch(`/api/v1/roles/${ids.role}`, { headers: write, data: { name: 'Hijacked' } })).status()).toBe(404)
    expect((await page.request.delete(`/api/v1/roles/${ids.role}`, { headers: write })).status()).toBe(404)
    // and none of it can be borrowed: a role or a manager of the demo office cannot be used here
    const staffRole = (await (await page.request.get('/api/v1/roles', { headers: DASH_HEADERS })).json() as { id: string, name: string }[]).find(r => r.name === 'Staff')!.id
    expect((await page.request.post('/api/v1/admin/employees', { headers: write, data: { name: 'X', email: 'x.iso@test.com', roleId: ids.role } })).status()).toBe(422)
    expect((await page.request.post('/api/v1/admin/employees', { headers: write, data: { name: 'X', email: 'x.iso@test.com', roleId: Number(staffRole), managerId: Number(ids.tina) } })).status()).toBe(403)
  })

  test('the platform routes and the demo office\'s addresses are closed too', async ({ page }) => {
    for (const path of ['/platform/organizations', '/platform/superadmins', '/platform/audit', '/platform/organizations/1/office/employees'])
      expect((await page.request.get(`/api/v1${path}`, { headers: DASH_HEADERS })).status(), path).toBe(403)
  })
})

test.describe('a lead and a staff member of the other organization', () => {
  test('the lead sees their team, the staff member only themselves', async ({ browser }) => {
    const lena = await otherPerson(browser, 'leadB')
    await lena.goto('/user')
    await expect(lena.getByRole('row')).toHaveCount(4) // Lena, Ben, Bianca and the header
    await expect(lena.getByRole('row').filter({ hasText: 'Bea Admin' })).toHaveCount(0)
    await lena.context().close()

    const ben = await otherPerson(browser, 'staffB')
    await ben.goto('/user')
    await expect(ben.getByRole('row')).toHaveCount(2)
    await expect(ben.getByRole('row').filter({ hasText: 'Ben Staff' })).toBeVisible()
    await ben.context().close()
  })

  test('the demo office\'s admin never sees the other organization', async ({ browser }) => {
    const demo = await otherPerson(browser, 'oic')
    await demo.goto('/user/people')
    for (const name of OTHER_OFFICE) await expect(demo.getByText(name)).toHaveCount(0)
    expect((await demo.request.get('/api/v1/employees?includeDeactivated=1', { headers: DASH_HEADERS })).status()).toBe(200)
    const list = await (await demo.request.get('/api/v1/employees?includeDeactivated=1', { headers: DASH_HEADERS })).json() as { email: string }[]
    expect(list.some(p => p.email.endsWith('.b@test.com') || p.email === ACCOUNTS.staffB)).toBe(false)
    await demo.context().close()
  })
})
