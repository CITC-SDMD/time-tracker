import { apiGet, as, expect, idOf, test } from '../fixtures'

test.describe('a person\'s day as the OIC', () => {
  test.use(as('oic'))

  test('shows who the person is and their live state', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    await expect(page.getByRole('heading', { name: 'Dan Ramos' })).toBeVisible()
    await expect(page.getByText('Lead Developer')).toBeVisible()
    await expect(page.getByText('Reports to Tina Cruz')).toBeVisible()
    await expect(page.getByText('Tracking', { exact: true })).toBeVisible()
  })

  test('today has totals, apps, a timeline and sessions that agree', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    const totals = page.getByLabel('Totals for the day')
    for (const label of ['Tracked', 'Active', 'Idle']) await expect(totals.getByText(label, { exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Apps' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Timeline' })).toBeVisible()
    await expect(page.getByLabel('Timeline of the day')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Sessions' })).toBeVisible()
    expect(await page.locator('tbody tr').count()).toBeGreaterThan(0)
    await expect(page.getByRole('columnheader', { name: 'Duration' })).toBeVisible()
    await expect(page.getByText('office time')).toBeVisible()
  })

  test('the app list is at most five apps plus Other', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    await expect(page.getByRole('heading', { name: 'Apps' })).toBeVisible()
    const items = await page.locator('h2:has-text("Apps") + div li').count()
    expect(items).toBeLessThanOrEqual(6)
  })

  test('Today and Yesterday switch the day, and the picked date is shown', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    await expect(page.getByLabel('Totals for the day')).toBeVisible()
    const dateInput = page.getByLabel('Pick a date')
    const today = await dateInput.inputValue()
    await page.getByRole('button', { name: 'Yesterday' }).click()
    await expect(dateInput).not.toHaveValue(today)
    const yesterday = await dateInput.inputValue()
    expect(new Date(today).getTime() - new Date(yesterday).getTime()).toBe(86_400_000)
    await page.getByRole('button', { name: 'Today' }).click()
    await expect(dateInput).toHaveValue(today)
  })

  test('a day with nothing tracked says so', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    await page.getByLabel('Pick a date').fill('2020-01-15')
    await expect(page.getByText('No tracked time on this day')).toBeVisible()
    await expect(page.getByLabel('Totals for the day').getByText('0s').first()).toBeVisible()
  })

  test('a past weekday with a saved summary shows its totals', async ({ page }) => {
    const id = await idOf(page, 'pm1@test.com')
    const res = await apiGet(page, `/employees/${id}/summary?from=${new Date(Date.now() - 25 * 86_400_000).toISOString().slice(0, 10)}&to=${new Date().toISOString().slice(0, 10)}`)
    const days = await res.json() as { day: string, trackedSeconds: number }[]
    expect(days.length).toBeGreaterThan(8)
    await page.goto(`/user/employees/${id}`)
    await page.getByLabel('Pick a date').fill(days[0]!.day)
    await expect(page.getByRole('heading', { name: 'Apps' })).toBeVisible()
    await expect(page.getByLabel('Totals for the day')).not.toContainText('Tracked0s')
  })

  test('the OIC can view their own day', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'oic@test.com')}`)
    await expect(page.getByRole('heading', { name: 'OIC' })).toBeVisible()
  })

  test('an id that does not exist shows the not-allowed message and no data', async ({ page }) => {
    await page.goto('/user/employees/999999')
    await expect(page.getByText('You cannot view this person.')).toBeVisible()
    await expect(page.getByLabel('Totals for the day')).toHaveCount(0)
  })

  test('a non-numeric id also shows the message', async ({ page }) => {
    await page.goto('/user/employees/abc')
    await expect(page.getByText('You cannot view this person.')).toBeVisible()
  })
})

test.describe('a person\'s day is limited to the caller\'s branch', () => {
  test.use(as('tl1'))

  test('a team leader cannot open someone outside their team', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/employees\/\d+\/(summary|timeline) 403/)
    const own = await idOf(page, 'dev2@test.com')
    await page.goto(`/user/employees/${own}`)
    await expect(page.getByRole('heading', { name: 'Dana Uy' })).toBeVisible()
    // Dex Tan is in another team: not in the team leader's list, so the page refuses without asking for data
    const oic = await (await page.context().browser()!.newContext(as('oic') as never)).newPage()
    await oic.goto('/user')
    const other = await idOf(oic, 'dev3@test.com')
    await oic.context().close()
    await page.goto(`/user/employees/${other}`)
    await expect(page.getByText('You cannot view this person.')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Dex Tan' })).toHaveCount(0)
  })

  test('the API itself refuses the direct calls for that person', async ({ page }) => {
    const oicPage = await (await page.context().browser()!.newContext(as('oic') as never)).newPage()
    await oicPage.goto('/user')
    const other = await idOf(oicPage, 'dev3@test.com')
    await oicPage.context().close()
    await page.goto('/user')
    for (const path of ['summary?from=2026-01-01&to=2026-12-31', 'timeline?day=2026-01-01']) {
      const res = await apiGet(page, `/employees/${other}/${path}`)
      expect(res.status()).toBe(403)
    }
  })
})
