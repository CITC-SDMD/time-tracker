import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { as, expect, idOf, test } from '../fixtures'

// axe-core scans every OIC page in both themes. Serious and critical findings fail the test; the
// full list is attached to the report either way so nothing is hidden.
async function scan(page: Page, name: string, testInfo: import('@playwright/test').TestInfo) {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
  await testInfo.attach(`axe-${name}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' })
  const bad = results.violations.filter(v => v.impact === 'serious' || v.impact === 'critical')
  expect(bad.map(v => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(' | ')}`), name).toEqual([])
}

const PAGES = ['/user', '/user/people', '/user/reports', '/user/settings', '/user/audit', '/user/profile']

for (const mode of ['light', 'dark'] as const) {
  test.describe(`accessibility in ${mode} mode`, () => {
    test.use(as('oic'))

    test.beforeEach(async ({ page }) => {
      await page.emulateMedia({ colorScheme: mode })
      await page.addInitScript(m => localStorage.setItem('theme', m), mode)
    })

    for (const path of PAGES) {
      test(`${path}`, async ({ page }, testInfo) => {
        await page.goto(path)
        await expect(page.locator('main h1, h1').first()).toBeVisible()
        await expect(page.getByText(/^Loading/)).toHaveCount(0)
        await page.waitForLoadState('networkidle')
        await scan(page, `${mode}${path.replaceAll('/', '-')}`, testInfo)
      })
    }

    test('a person\'s day', async ({ page }, testInfo) => {
      await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
      await expect(page.getByLabel('Timeline of the day')).toBeVisible()
      await scan(page, `${mode}-person`, testInfo)
    })

    test('the Add dialog', async ({ page }, testInfo) => {
      await page.goto('/user/people')
      await page.getByRole('button', { name: 'Add Project Manager' }).click()
      await expect(page.locator('[id^="headlessui-dialog-panel"]')).toHaveCSS('opacity', '1')
      await scan(page, `${mode}-add-dialog`, testInfo)
    })
  })
}

test.describe('public pages', () => {
  for (const path of ['/', '/forgot-password', '/reset-password']) {
    test(`${path} in light and dark`, async ({ page }, testInfo) => {
      for (const mode of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme: mode })
        await page.addInitScript(m => localStorage.setItem('theme', m), mode)
        await page.goto(path)
        await page.waitForLoadState('networkidle')
        await scan(page, `${mode}-public${path.replaceAll('/', '-')}`, testInfo)
      }
    })
  }
})

test.describe('keyboard use', () => {
  test('sign-in can be completed with the keyboard alone', async ({ page }) => {
    await page.goto('/')
    await page.getByLabel('Email address').focus()
    await page.keyboard.type('oic@test.com')
    await page.keyboard.press('Tab')
    await page.keyboard.type('password')
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/user$/)
  })

  test.describe('signed in', () => {
    test.use(as('oic'))

    test('the Add dialog traps focus, closes with Escape, and gives focus back', async ({ page }) => {
      await page.goto('/user/people')
      const opener = page.getByRole('button', { name: 'Add Project Manager' })
      await opener.focus()
      await page.keyboard.press('Enter')
      await expect(page.getByRole('dialog').getByLabel('Full name')).toBeFocused()
      for (let i = 0; i < 8; i++) await page.keyboard.press('Tab')
      expect(await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'))).toBe(true)
      await page.keyboard.press('Escape')
      await expect(page.getByRole('dialog')).toHaveCount(0)
      await expect(opener).toBeFocused()
    })

    test('the profile menu opens with the keyboard and Escape closes it', async ({ page }) => {
      await page.goto('/user')
      const button = page.getByRole('button', { name: /open user menu/i })
      await button.focus()
      await page.keyboard.press('Enter')
      await expect(page.getByRole('menuitem', { name: 'Sign out' })).toBeVisible()
      await page.keyboard.press('Escape')
      await expect(page.getByRole('menuitem', { name: 'Sign out' })).toHaveCount(0)
    })

    test('every button and link on the People page has a name', async ({ page }) => {
      await page.goto('/user/people')
      await expect(page.getByRole('row').nth(2)).toBeVisible()
      const nameless = await page.evaluate(() => [...document.querySelectorAll('button, a')].filter((el) => {
        const t = (el.textContent ?? '').trim() || el.getAttribute('aria-label') || el.getAttribute('title')
        return !t
      }).map(el => el.outerHTML.slice(0, 80)))
      expect(nameless).toEqual([])
    })
  })
})
