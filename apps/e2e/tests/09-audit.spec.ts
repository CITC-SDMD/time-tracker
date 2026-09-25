import { DASH_HEADERS, apiGet, as, expect, idOf, test } from '../fixtures'

const from = (page: import('@playwright/test').Page) => page.getByLabel('From', { exact: true })
const to = (page: import('@playwright/test').Page) => page.getByLabel('To', { exact: true })
const who = (page: import('@playwright/test').Page) => page.getByLabel('Person')

test.describe.serial('audit log as the OIC', () => {
  test.use(as('oic'))

  test.beforeAll(async ({ browser }) => {
    // enough entries for a second page: looking at someone's timeline is itself written to the log
    const ctx = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: '.auth/oic.json' })
    const page = await ctx.newPage()
    await page.goto('/user')
    const id = await idOf(page, 'dev1@test.com')
    // one entry per person per day looked at, so ask for 60 different days
    for (let i = 0; i < 60; i++) await apiGet(page, `/employees/${id}/timeline?day=${new Date(Date.UTC(2025, 0, 1 + i)).toISOString().slice(0, 10)}`)
    // one settings change, and an account that is added and deleted again (its name must stay in the log)
    const xsrf = decodeURIComponent((await ctx.cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const headers = { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }
    await page.request.put('/api/v1/admin/settings', { headers, data: { idleThresholdSeconds: 300 } })
    const ghost = await page.request.post('/api/v1/admin/employees', { headers, data: { name: 'Audit Ghost', email: 'ghost.e2e@test.com', role: 'project_manager' } })
    await page.request.delete(`/api/v1/admin/employees/${(await ghost.json()).id}`, { headers })
    await ctx.close()
  })

  test('READ: newest first, with who, what, to whom and details', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible()
    for (const col of ['When', 'Who', 'Did', 'To', 'Details']) await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    await expect(page.locator('tbody tr')).toHaveCount(50)
    const first = page.locator('tbody tr').first()
    await expect(first).toContainText('OIC')
    await expect(first).toContainText('Deleted an account')
    await expect(first).toContainText('Audit Ghost')
    await expect(page.locator('tbody tr', { hasText: 'Viewed a timeline' }).first()).toContainText('Dan Ramos')
    const stamps = await page.locator('tbody tr td:nth-child(1)').allTextContents()
    expect(stamps[0]).toMatch(/\d{1,2} \w{3,4}, \d\d:\d\d/)
  })

  test('READ: Load older entries appends the next page and then disappears', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.locator('tbody tr')).toHaveCount(50)
    await page.getByRole('button', { name: 'Load older entries' }).click()
    await expect(page.locator('tbody tr').nth(50)).toBeVisible()
    expect(await page.locator('tbody tr').count()).toBeGreaterThan(50)
    await expect(page.getByRole('button', { name: 'Load older entries' })).toHaveCount(0)
  })

  test('every action the office has written has a plain-words label', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.getByLabel('Action').locator('option').first()).toHaveText('All actions')
    const actions = await page.getByLabel('Action').locator('option').allTextContents()
    for (const label of ['Added an account', 'Deactivated an account', 'Reactivated an account', 'Deleted an account', 'Moved a person to another manager', 'Sent a new set-password link', 'Changed office settings', 'Viewed a timeline', 'Reset a password with an emailed link', 'Changed their password', 'Downloaded a report'])
      expect(actions).toContain(label)
    // no raw codes leak into the table
    const text = await page.locator('tbody').innerText()
    expect(text).not.toMatch(/\b(employee|settings|password|report|timeline)\.\w+/)
  })

  test('the Action filter narrows the list and Clear filters brings it back', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.locator('tbody tr')).toHaveCount(50)
    await page.getByLabel('Action').selectOption({ label: 'Changed office settings' })
    await expect(page.locator('tbody tr').first()).toContainText('Changed office settings')
    const kinds = new Set((await page.locator('tbody tr td:nth-child(3)').allTextContents()).map(t => t.trim()))
    expect([...kinds]).toEqual(['Changed office settings'])
    await page.getByRole('button', { name: 'Clear filters' }).click()
    await expect(page.locator('tbody tr')).toHaveCount(50)
    await expect(page.getByLabel('Action')).toHaveValue('')
  })

  test('the Person search matches who did it or who it was done to', async ({ page }) => {
    await page.goto('/user/audit')
    await who(page).fill('Dan Ramos')
    await expect(page.locator('tbody tr').first()).toContainText('Dan Ramos')
    for (const t of await page.locator('tbody tr').allTextContents()) expect(t).toContain('Dan Ramos')
    await who(page).fill('zzzz-nobody')
    await expect(page.getByText('No entries match these filters')).toBeVisible()
    await page.getByRole('button', { name: 'Clear filters' }).click()
    await expect(page.locator('tbody tr')).toHaveCount(50)
  })

  test('the Person search treats % and _ as plain text', async ({ page }) => {
    await page.goto('/user/audit')
    await who(page).fill('%')
    await expect(page.getByText('No entries match these filters')).toBeVisible()
  })

  test('the date range filters in office time', async ({ page }) => {
    await page.goto('/user/audit')
    await from(page).fill('2020-01-01')
    await to(page).fill('2020-12-31')
    await expect(page.getByText('No entries match these filters')).toBeVisible()
    await to(page).fill('2099-12-31')
    await from(page).fill('2020-01-01')
    await expect(page.locator('tbody tr')).toHaveCount(50)
  })

  test('a backwards date range is refused in the form and nothing is asked of the server', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.locator('tbody tr')).toHaveCount(50)
    let calls = 0
    page.on('request', (r) => {
      if (r.url().includes('/admin/audit')) calls++
    })
    await from(page).fill('2026-05-10')
    await to(page).fill('2026-05-01')
    await expect(page.getByText('"To" cannot be before "From".')).toBeVisible()
    await page.waitForTimeout(600)
    expect(calls).toBeLessThanOrEqual(1)
  })

  test('filters combine, and Load older entries keeps working inside a filter', async ({ page }) => {
    await page.goto('/user/audit')
    await page.getByLabel('Action').selectOption({ label: 'Viewed a timeline' })
    await who(page).fill('Dan')
    await expect(page.locator('tbody tr')).toHaveCount(50)
    await page.getByRole('button', { name: 'Load older entries' }).click()
    await expect(page.locator('tbody tr').nth(50)).toBeVisible()
  })

  test('typing quickly shows the result of the last thing typed', async ({ page }) => {
    await page.goto('/user/audit')
    await who(page).pressSequentially('Dan Ramos', { delay: 40 })
    await who(page).fill('Dan Ramos')
    await expect(page.locator('tbody tr').first()).toContainText('Dan Ramos')
    await who(page).fill('')
    await expect(page.locator('tbody tr')).toHaveCount(50)
  })

  test('a server error is shown instead of an empty table', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/admin\/audit 500/)
    await page.route('**/api/v1/admin/audit**', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"SERVER_ERROR","message":"Something went wrong."}}' }))
    await page.goto('/user/audit')
    await expect(page.getByText('Something went wrong.')).toBeVisible()
  })

  test('names stay in the log after an account is deleted', async ({ page }) => {
    await page.goto('/user/audit')
    await who(page).fill('Audit Ghost')
    await expect(page.locator('tbody tr').first()).toContainText('Audit Ghost')
  })
})
