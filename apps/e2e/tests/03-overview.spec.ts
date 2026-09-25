import type { Page } from '@playwright/test'
import { as, expect, test } from '../fixtures'

const OFFICE = ['OIC', 'Paula Reyes', 'Pedro Santos', 'Tina Cruz', 'Tomas Diaz', 'Tess Lim', 'Dan Ramos', 'Dana Uy', 'Dex Tan', 'Quinn Go', 'Cara Sy', 'Sam Ong']

function card(page: Page, label: string) {
  return page.locator('section').filter({ hasText: label }).getByText(label, { exact: true }).locator('xpath=..')
}

function toSeconds(t: string): number {
  const h = /(\d+)h/.exec(t)?.[1] ?? '0'
  const m = /(\d+)m/.exec(t)?.[1] ?? '0'
  const s = /(\d+)s/.exec(t)?.[1] ?? '0'
  return Number(h) * 3600 + Number(m) * 60 + Number(s)
}

test.describe('overview as the OIC', () => {
  test.use(as('oic'))

  test('shows the whole office with the right columns', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible()
    await expect(page.getByText('Everyone in the office, right now and today.')).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(OFFICE.length + 1)
    for (const name of OFFICE) await expect(page.getByRole('row').filter({ hasText: name }).first()).toBeVisible()
    for (const col of ['Name', 'Role', 'Status', 'Tracked', 'Active', 'Idle', 'Current app', 'Last activity'])
      await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
  })

  test('every live state is shown in words, and offline says when the person was last seen', async ({ page }) => {
    await page.goto('/user')
    const row = (name: string) => page.getByRole('row').filter({ hasText: name })
    await expect(row('Dan Ramos')).toContainText('Tracking')
    await expect(row('Dana Uy')).toContainText('Idle')
    await expect(row('Dex Tan')).toContainText('Paused')
    await expect(row('Quinn Go')).toContainText('Not tracking')
    await expect(row('Cara Sy')).toContainText(/Offline \(last seen \d\d:\d\d\)/)
    await expect(row('Dan Ramos')).toContainText('Visual Studio Code')
    await expect(row('Dan Ramos')).toContainText('Lead Developer')
  })

  test('the stat cards agree with the table', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(OFFICE.length + 1)
    const statuses = await page.locator('tbody tr td:nth-child(3)').allTextContents()
    const tracking = statuses.filter(s => s.trim() === 'Tracking').length
    const idle = statuses.filter(s => s.trim() === 'Idle').length
    await expect(card(page, 'Tracking now')).toContainText(String(tracking))
    await expect(card(page, 'Idle now')).toContainText(String(idle))
    await expect(card(page, 'Not tracking')).toContainText(String(OFFICE.length - tracking - idle))
  })

  test('the totals cards equal the sum of the table', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(OFFICE.length + 1)
    const cells = await page.getByRole('row').evaluateAll(rows => rows.slice(1).map(r => [...r.querySelectorAll('td')].map(td => td.textContent?.trim() ?? '')))
    const sum = (i: number) => cells.reduce((a, r) => a + toSeconds(r[i]!), 0)
    const shown = async (label: string) => toSeconds((await card(page, label).textContent()) ?? '')
    // every person is rounded to the minute, so the total may differ by up to a minute per person
    expect(Math.abs(await shown('Tracked today') - sum(3))).toBeLessThan(OFFICE.length * 60)
    expect(Math.abs(await shown('Active today') - sum(4))).toBeLessThan(OFFICE.length * 60)
  })

  test('a name opens that person, and the back link returns', async ({ page }) => {
    await page.goto('/user')
    await page.getByRole('link', { name: 'Dan Ramos' }).click()
    await expect(page).toHaveURL(/\/user\/employees\/\d+$/)
    await expect(page.getByRole('heading', { name: 'Dan Ramos' })).toBeVisible()
    await page.getByRole('link', { name: '← Overview' }).click()
    await expect(page).toHaveURL(/\/user$/)
  })

  test('the Refresh button asks the server again', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(OFFICE.length + 1)
    const again = page.waitForRequest(r => r.url().includes('/api/v1/employees') && r.method() === 'GET')
    await page.getByRole('button', { name: 'Refresh' }).click()
    await again
  })

  test('it refreshes by itself every minute, but only while the tab is visible', async ({ page }) => {
    await page.clock.install()
    let calls = 0
    page.on('request', (r) => {
      if (r.url().includes('/api/v1/employees')) calls++
    })
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(OFFICE.length + 1)
    const start = calls
    await page.clock.runFor(60_000)
    await expect.poll(() => calls).toBeGreaterThan(start)
    // hide the tab: the next ticks must not call the server
    await page.evaluate(() => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }))
    const hidden = calls
    await page.clock.runFor(120_000)
    await page.waitForTimeout(500)
    expect(calls).toBe(hidden)
  })

  test('a server error shows a clear message instead of an empty table', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/employees 500/)
    await page.route('**/api/v1/employees', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"SERVER_ERROR","message":"Something went wrong."}}' }))
    await page.goto('/user')
    await expect(page.getByText('Something went wrong.')).toBeVisible()
  })

  test('the sidebar lists every OIC page and marks the current one', async ({ page }) => {
    await page.goto('/user')
    for (const name of ['Overview', 'People', 'Reports', 'Settings', 'Audit log'])
      await expect(page.getByRole('link', { name, exact: true }).first()).toBeVisible()
    await expect(page.getByRole('link', { name: 'Overview', exact: true }).first()).toHaveAttribute('aria-current', 'page')
  })
})

test.describe('overview as a project manager', () => {
  test.use(as('pm1'))

  test('shows only their own branch and no OIC-only pages', async ({ page }) => {
    await page.goto('/user')
    const names = ['Paula Reyes', 'Tina Cruz', 'Tomas Diaz', 'Dan Ramos', 'Dana Uy', 'Dex Tan', 'Quinn Go']
    await expect(page.getByRole('row')).toHaveCount(names.length + 1)
    for (const n of names) await expect(page.getByRole('row').filter({ hasText: n })).toBeVisible()
    for (const n of ['Pedro Santos', 'Tess Lim', 'Cara Sy', 'Sam Ong']) await expect(page.getByRole('row').filter({ hasText: n })).toHaveCount(0)
    await expect(page.getByText('You and everyone who reports to you, right now and today.')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Settings', exact: true })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Audit log', exact: true })).toHaveCount(0)
  })
})

test.describe('overview as a team leader', () => {
  test.use(as('tl1'))

  test('shows only their own team', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(4)
    for (const n of ['Tina Cruz', 'Dan Ramos', 'Dana Uy']) await expect(page.getByRole('row').filter({ hasText: n })).toBeVisible()
  })
})

test.describe('someone who is not signed in', () => {
  test('is sent to the sign-in page', async ({ page }) => {
    await page.goto('/user')
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible()
  })
})
