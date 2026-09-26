import { as, expect, idOf, test } from '../fixtures'

// Every page, for every role that can open it, in both themes and on a desktop and a phone. The
// shared fixture already fails a test on any console error or warning, uncaught exception or
// unexpected 4xx/5xx, so all that is asserted here is that the page rendered and fits the screen.
const ROLES = {
  oic: ['/user', '/user/people', '/user/reports', '/user/roles', '/user/settings', '/user/audit', '/user/profile'],
  pm1: ['/user', '/user/people', '/user/reports', '/user/profile'],
  tl1: ['/user', '/user/people', '/user/reports', '/user/profile'],
} as const

const SCREENS = [
  { name: 'desktop', viewport: { width: 1280, height: 800 } },
  { name: 'phone', viewport: { width: 375, height: 800 } },
] as const

for (const [role, pages] of Object.entries(ROLES) as [keyof typeof ROLES, readonly string[]][]) {
  for (const screen of SCREENS) {
    for (const mode of ['light', 'dark'] as const) {
      test.describe(`${role} on a ${screen.name} in ${mode} mode`, () => {
        test.use({ ...as(role), viewport: screen.viewport, colorScheme: mode })

        for (const path of pages) {
          test(path, async ({ page }) => {
            await page.addInitScript(m => localStorage.setItem('theme', m), mode)
            await page.goto(path)
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
            await page.waitForLoadState('networkidle')
            // no spinner left behind, no error banner, nothing scrolls sideways
            await expect(page.locator('[role="status"]:has-text("Loading")')).toHaveCount(0)
            await expect(page.locator('[role="alert"]')).toHaveCount(0)
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'horizontal scroll').toBe(true)
            expect(await page.title()).toContain('Time Tracker')
          })
        }

        test('a person\'s day', async ({ page }) => {
          await page.addInitScript(m => localStorage.setItem('theme', m), mode)
          await page.goto('/user')
          const id = await idOf(page, role === 'tl1' ? 'dev2@test.com' : 'dev1@test.com')
          await page.goto(`/user/employees/${id}`)
          await expect(page.getByLabel('Timeline of the day')).toBeVisible()
          await page.waitForLoadState('networkidle')
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'horizontal scroll').toBe(true)
        })
      })
    }
  }
}

test.describe('the platform pages', () => {
  test.use(as('admin'))

  for (const path of ['/platform', '/platform/superadmins', '/platform/settings', '/platform/audit', '/user/profile']) {
    test(`${path} opens without errors`, async ({ page }) => {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await page.waitForLoadState('networkidle')
      await expect(page.locator('[role="alert"]')).toHaveCount(0)
    })
  }
})

test.describe('unknown pages', () => {
  test.use(as('oic'))

  test('a page that does not exist shows the not-found page, not a blank screen', async ({ page, allow }) => {
    allow(/NUXT_E1005/) // Nuxt logs every unknown address once
    await page.goto('/user/nothing-here')
    await expect(page.locator('body')).not.toBeEmpty()
    await expect(page.getByText(/not found|404|page could not be found/i).first()).toBeVisible()
  })
})
