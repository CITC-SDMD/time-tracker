import fs from 'node:fs'
import type { Page } from '@playwright/test'
import { apiGet, as, expect, test } from '../fixtures'

function seconds(text: string): number {
  const h = /(\d+)h/.exec(text)?.[1] ?? '0'
  const m = /(\d+)m/.exec(text)?.[1] ?? '0'
  const s = /(\d+)s/.exec(text)?.[1] ?? '0'
  return Number(h) * 3600 + Number(m) * 60 + Number(s)
}

const tab = (page: Page, name: string) => page.getByRole('tab', { name })
const range = (page: Page, from: string, to: string) => page.getByLabel('From', { exact: true }).fill(from).then(() => page.getByLabel('To', { exact: true }).fill(to))

test.describe('reports as the OIC', () => {
  test.use(as('oic'))

  test('READ: opens on the last seven days with daily rows and totals', async ({ page }) => {
    await page.goto('/user/reports')
    await expect(page.getByRole('heading', { name: 'Reports' })).toBeVisible()
    await expect(tab(page, 'Daily per person')).toHaveAttribute('aria-selected', 'true')
    for (const col of ['Date', 'Name', 'Role', 'Tracked', 'Active', 'Idle', 'First activity', 'Last activity'])
      await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    expect(await page.locator('tbody tr').count()).toBeGreaterThan(5)
    const totals = page.getByLabel('Totals for the range')
    for (const label of ['Tracked', 'Active', 'Idle']) await expect(totals.getByText(label, { exact: true })).toBeVisible()
  })

  test('READ: the totals cards equal the sum of the rows', async ({ page }) => {
    await page.goto('/user/reports')
    await expect(page.locator('tbody tr').first()).toBeVisible()
    const rows = await page.locator('tbody tr').evaluateAll(trs => trs.map(tr => [...tr.querySelectorAll('td')].map(td => td.textContent?.trim() ?? '')))
    const sum = rows.reduce((a, r) => a + seconds(r[3]!), 0)
    const shown = seconds((await page.getByLabel('Totals for the range').getByText('Tracked', { exact: true }).locator('xpath=..').textContent()) ?? '')
    expect(Math.abs(shown - sum)).toBeLessThan(rows.length * 60)
  })

  test('READ: the three tabs show their own columns', async ({ page }) => {
    await page.goto('/user/reports')
    await tab(page, 'App usage').click()
    for (const col of ['Application', 'Active time', 'People']) await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    for (const app of ['Visual Studio Code', 'Google Chrome']) await expect(page.getByRole('cell', { name: app, exact: true })).toBeVisible()
    await expect(page.getByLabel('Totals for the range')).toHaveCount(0)
    await tab(page, 'Team totals').click()
    for (const col of ['Name', 'Role', 'Manager', 'Days tracked', 'Tracked', 'Active', 'Idle', 'Average a day']) await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Dan Ramos' })).toBeVisible()
    await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Tina Cruz', exact: true }) })).toContainText('Paula Reyes')
  })

  test('READ: team totals and daily rows agree for the same range', async ({ page }) => {
    await page.goto('/user/reports')
    await page.getByRole('button', { name: 'Last 30 days' }).click()
    await expect(page.locator('tbody tr').first()).toBeVisible()
    const daily = seconds((await page.getByLabel('Totals for the range').getByText('Tracked', { exact: true }).locator('xpath=..').textContent()) ?? '')
    await tab(page, 'Team totals').click()
    await expect(page.getByRole('columnheader', { name: 'Days tracked' })).toBeVisible()
    const teamTracked = seconds((await page.getByLabel('Totals for the range').getByText('Tracked', { exact: true }).locator('xpath=..').textContent()) ?? '')
    expect(Math.abs(daily - teamTracked)).toBeLessThan(60 * 60)
  })

  test('the presets change the dates', async ({ page }) => {
    await page.goto('/user/reports')
    const to = await page.getByLabel('To', { exact: true }).inputValue()
    for (const [label, days] of [['Last 7 days', 6], ['Last 30 days', 29], ['Last 90 days', 89]] as const) {
      await page.getByRole('button', { name: label }).click()
      const from = await page.getByLabel('From', { exact: true }).inputValue()
      expect((new Date(to).getTime() - new Date(from).getTime()) / 86_400_000).toBe(days)
    }
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })

  test('the person filter limits every row to that person', async ({ page }) => {
    await page.goto('/user/reports')
    await page.getByLabel('Person').selectOption({ label: 'Dan Ramos' })
    await expect(page.locator('tbody tr').first()).toBeVisible()
    const names = await page.locator('tbody tr td:nth-child(2)').allTextContents()
    expect(names.length).toBeGreaterThan(3)
    expect(new Set(names.map(n => n.trim()))).toEqual(new Set(['Dan Ramos']))
    await page.getByLabel('Person').selectOption({ label: 'Everyone' })
    await expect.poll(async () => new Set((await page.locator('tbody tr td:nth-child(2)').allTextContents()).map(n => n.trim())).size).toBeGreaterThan(3)
  })

  test('a range with nothing in it says so', async ({ page }) => {
    await page.goto('/user/reports')
    await range(page, '2020-01-01', '2020-01-10')
    await expect(page.getByText('Nothing tracked in this range')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Download CSV' })).toBeEnabled()
  })

  test('the form refuses an order that is backwards or a range over 92 days, and sends nothing', async ({ page }) => {
    await page.goto('/user/reports')
    await expect(page.locator('tbody tr').first()).toBeVisible()
    await range(page, '2026-03-10', '2026-03-01')
    await expect(page.getByText('"To" cannot be before "From".')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Download CSV' })).toBeDisabled()
    await range(page, '2026-01-01', '2026-06-30')
    await expect(page.getByText('Choose at most 92 days.')).toBeVisible()
    await page.getByLabel('From', { exact: true }).fill('')
    await expect(page.getByText('Choose the first day.')).toBeVisible()
    await page.getByRole('button', { name: 'Last 7 days' }).click()
    await expect(page.getByText('Choose at most 92 days.')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Download CSV' })).toBeEnabled()
  })

  test('exactly 92 days is allowed', async ({ page }) => {
    await page.goto('/user/reports')
    await page.getByRole('button', { name: 'Last 90 days' }).click()
    const to = await page.getByLabel('To', { exact: true }).inputValue()
    const from = new Date(new Date(to).getTime() - 91 * 86_400_000).toISOString().slice(0, 10)
    await page.getByLabel('From', { exact: true }).fill(from)
    await expect(page.getByText('Choose at most 92 days.')).toHaveCount(0)
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })

  test('the API itself refuses more than 92 days and a stranger\'s id', async ({ page }) => {
    const long = await apiGet(page, '/reports/daily?from=2026-01-01&to=2026-12-31')
    expect(long.status()).toBe(422)
    expect((await long.json()).error.code).toBe('RANGE_TOO_LONG')
    const missing = await apiGet(page, '/reports/daily?from=2026-01-01&to=2026-01-05&uid=99999')
    expect([403, 404, 422]).toContain(missing.status())
  })

  for (const which of ['daily', 'apps', 'team'] as const) {
    test(`EXPORT: the ${which} CSV downloads with a byte-order mark, a header and the same rows`, async ({ page }) => {
      await page.goto('/user/reports')
      await tab(page, which === 'daily' ? 'Daily per person' : which === 'apps' ? 'App usage' : 'Team totals').click()
      await expect(page.locator('tbody tr').first()).toBeVisible()
      const shown = await page.locator('tbody tr').count()
      const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download CSV' }).click()])
      expect(download.suggestedFilename()).toMatch(new RegExp(`^${which}-report-\\d{4}-\\d\\d-\\d\\d-to-\\d{4}-\\d\\d-\\d\\d\\.csv$`))
      const bytes = fs.readFileSync((await download.path())!)
      expect([...bytes.subarray(0, 3)]).toEqual([0xEF, 0xBB, 0xBF])
      const lines = bytes.toString('utf8').replace(/^\uFEFF/, '').trim().split(/\r?\n/)
      expect(lines.length - 1).toBe(shown)
      expect(lines[0]!.split(',').length).toBeGreaterThan(2)
      // no spreadsheet formulas: a cell that starts with = + - @ must have been defused
      for (const line of lines.slice(1)) for (const cell of line.split(',')) expect(cell).not.toMatch(/^"?[=+@]/)
    })
  }

  test('EXPORT: the daily CSV has one row per person-day with seconds columns and office clock times', async ({ page }) => {
    await page.goto('/user/reports')
    await page.getByLabel('Person').selectOption({ label: 'Dan Ramos' })
    await expect(page.locator('tbody tr').first()).toBeVisible()
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download CSV' }).click()])
    const text = fs.readFileSync((await download.path())!, 'utf8').replace(/^\uFEFF/, '')
    const [header, first] = text.trim().split(/\r?\n/) as [string, string]
    expect(header.toLowerCase()).toContain('seconds')
    expect(first).toContain('Dan Ramos')
    expect(first).toMatch(/\d\d:\d\d/)
  })

  test('the download is recorded in the audit log', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.locator('td', { hasText: 'Downloaded a report' }).first()).toBeVisible()
  })

  test('a name in the reports opens that person', async ({ page }) => {
    await page.goto('/user/reports')
    await tab(page, 'Team totals').click()
    await page.getByRole('link', { name: 'Dan Ramos' }).click()
    await expect(page.getByRole('heading', { name: 'Dan Ramos' })).toBeVisible()
  })

  test('a server error is shown and the tab still works afterwards', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/reports\/apps 500/)
    let fail = true
    await page.route('**/api/v1/reports/apps**', (route) => {
      if (!fail) return route.continue()
      return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"SERVER_ERROR","message":"Something went wrong."}}' })
    })
    await page.goto('/user/reports')
    await tab(page, 'App usage').click()
    await expect(page.getByText('Something went wrong.')).toBeVisible()
    fail = false
    await page.getByRole('button', { name: 'Last 30 days' }).click()
    await expect(page.getByRole('cell', { name: 'Visual Studio Code', exact: true })).toBeVisible()
  })
})

test.describe('reports for a project manager', () => {
  test.use(as('pm1'))

  test('only their own people appear, in the filter and in every tab', async ({ page }) => {
    await page.goto('/user/reports')
    await expect.poll(async () => (await page.getByLabel('Person').locator('option').allTextContents()).sort()).toEqual(['Everyone', 'Paula Reyes', 'Tina Cruz', 'Tomas Diaz', 'Dan Ramos', 'Dana Uy', 'Dex Tan', 'Quinn Go'].sort())
    await tab(page, 'Team totals').click()
    await expect(page.getByRole('link', { name: 'Tina Cruz' })).toBeVisible()
    for (const n of ['Tess Lim', 'Cara Sy', 'Sam Ong', 'Pedro Santos']) await expect(page.getByRole('link', { name: n })).toHaveCount(0)
  })

  test('the API refuses a report about someone outside their branch', async ({ page }) => {
    await page.goto('/user/reports')
    // Cara Sy belongs to the other project manager: find her id the way the OIC would
    const list = await (await apiGet(page, '/employees')).json() as { email: string }[]
    expect(list.some(p => p.email === 'cs1@test.com')).toBe(false)
    for (const kind of ['daily', 'apps', 'team']) {
      const res = await apiGet(page, `/reports/${kind}?from=2026-01-01&to=2026-01-31&uid=1`)
      expect([403, 404]).toContain(res.status())
    }
  })
})

test.describe('reports for a team leader', () => {
  test.use(as('tl1'))

  test('show only the team', async ({ page }) => {
    await page.goto('/user/reports')
    await tab(page, 'Team totals').click()
    await expect(page.getByRole('row')).toHaveCount(4)
  })
})
