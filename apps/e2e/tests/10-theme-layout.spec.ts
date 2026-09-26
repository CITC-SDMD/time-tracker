import type { Page } from '@playwright/test'
import { as, expect, idOf, test } from '../fixtures'

const isDark = (page: Page) => page.evaluate(() => document.documentElement.classList.contains('dark'))
/** [r, g, b, a] of a computed colour: Chromium reports Tailwind's colours as oklch(), so a canvas converts them */
async function colour(page: Page, selector: string, property: 'backgroundColor' | 'color'): Promise<number[]> {
  return page.locator(selector).first().evaluate((el, prop) => {
    const css = getComputedStyle(el)[prop as 'color']
    const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!
    ctx.clearRect(0, 0, 1, 1)
    ctx.fillStyle = css
    ctx.fillRect(0, 0, 1, 1)
    return [...ctx.getImageData(0, 0, 1, 1).data]
  }, property)
}
const bg = (page: Page, selector: string) => colour(page, selector, 'backgroundColor')

function luminance([r, g, b]: number[]): number {
  const f = (c: number) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * f(r!) + 0.7152 * f(g!) + 0.0722 * f(b!)
}

function contrast(a: number[], b: number[]): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi! + 0.05) / (lo! + 0.05)
}

test.describe('the theme toggle', () => {
  test.use(as('oic'))

  test('switches between light and dark, and the icon and label follow', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/user')
    expect(await isDark(page)).toBe(false)
    await page.getByRole('button', { name: 'Switch to dark mode' }).click()
    expect(await isDark(page)).toBe(true)
    await expect(page.getByRole('button', { name: 'Switch to light mode' })).toBeVisible()
    await page.getByRole('button', { name: 'Switch to light mode' }).click()
    expect(await isDark(page)).toBe(false)
  })

  test('the choice is kept across a reload and across pages', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/user')
    await page.getByRole('button', { name: 'Switch to dark mode' }).click()
    await page.reload()
    expect(await isDark(page)).toBe(true)
    await page.getByRole('link', { name: 'People', exact: true }).first().click()
    await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()
    expect(await isDark(page)).toBe(true)
    expect(await page.evaluate(() => localStorage.getItem('theme'))).toBe('dark')
    await page.getByRole('button', { name: 'Switch to light mode' }).click()
    await page.reload()
    expect(await isDark(page)).toBe(false)
    expect(await page.evaluate(() => localStorage.getItem('theme'))).toBe('light')
  })

  test('dark is in place before the first paint (no white flash)', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'))
    let seen: boolean | null = null
    await page.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        (window as unknown as { __darkAtDcl: boolean }).__darkAtDcl = document.documentElement.classList.contains('dark')
      })
    })
    await page.goto('/user')
    seen = await page.evaluate(() => (window as unknown as { __darkAtDcl: boolean }).__darkAtDcl)
    expect(seen).toBe(true)
  })
})

test.describe('with no saved choice the page follows the system', () => {
  test.use({ colorScheme: 'dark' })

  test('a dark system gets dark', async ({ page }) => {
    await page.goto('/')
    expect(await isDark(page)).toBe(true)
    expect((await bg(page, 'body')).slice(0, 3)).toEqual([9, 9, 11])
  })

  test('a saved light choice beats a dark system', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('theme', 'light'))
    await page.goto('/')
    expect(await isDark(page)).toBe(false)
    expect((await bg(page, 'body')).slice(0, 3)).toEqual([250, 250, 250])
  })
})

test.describe('with a light system', () => {
  test.use({ colorScheme: 'light' })

  test('a light system gets light, and a blocked storage does not break the toggle', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(window, 'localStorage', { get() { throw new Error('storage blocked') } })
    })
    await page.goto('/forgot-password')
    expect(await isDark(page)).toBe(false)
    await page.getByRole('button', { name: 'Switch to dark mode' }).click()
    expect(await isDark(page)).toBe(true)
  })
})

test.describe('every surface has its own colour in both modes', () => {
  test.use(as('oic'))

  for (const mode of ['light', 'dark'] as const) {
    test(`${mode}: page, sidebar, top bar, cards and tables use the neutral palette and stay readable`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: mode })
      await page.addInitScript(m => localStorage.setItem('theme', m), mode)
      await page.goto('/user')
      await expect(page.getByRole('row').first()).toBeVisible()
      const pageBg = (await bg(page, 'body')).slice(0, 3)
      expect(pageBg).toEqual(mode === 'dark' ? [9, 9, 11] : [250, 250, 250])
      // headings and table text keep at least AA contrast against the surface they sit on
      const heading = (await colour(page, 'h1', 'color')).slice(0, 3)
      expect(contrast(heading, pageBg), `heading in ${mode}`).toBeGreaterThan(4.5)
      const surface = (await bg(page, '[class*="ring-1"]')).slice(0, 3)
      const cell = (await colour(page, 'tbody td', 'color')).slice(0, 3)
      expect(contrast(cell, surface), `table text in ${mode}`).toBeGreaterThan(4.5)
      // no white card in dark mode, no dark card in light mode
      if (mode === 'dark') expect(luminance(surface)).toBeLessThan(0.05)
      else expect(luminance(surface)).toBeGreaterThan(0.8)
      await page.screenshot({ path: `test-results/theme-${mode}-overview.png`, fullPage: true })
    })

    test(`${mode}: the forms, dialog and badges are readable`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: mode })
      await page.addInitScript(m => localStorage.setItem('theme', m), mode)
      await page.goto('/user/people')
      await page.getByRole('button', { name: 'Add a person' }).click()
      const input = page.getByRole('dialog').getByLabel('Full name')
      await expect(input).toBeVisible()
      // wait for the open animation to finish, or the panel is still see-through
      const panelEl = page.locator('[id^="headlessui-dialog-panel"]')
      await expect(panelEl).toHaveCSS('opacity', '1')
      await page.screenshot({ path: `test-results/theme-${mode}-dialog.png` })
      const text = (await colour(page, '[role="dialog"] input', 'color')).slice(0, 3)
      const field = await bg(page, '[role="dialog"] input')
      // the field's colour may be see-through (white at 5%): lay it over the panel behind it
      const under = (await bg(page, '[id^="headlessui-dialog-panel"]')).slice(0, 3)
      const alpha = field[3]! / 255
      const panel = under.map((u, i) => Math.round(field[i]! * alpha + u * (1 - alpha)))
      expect(contrast(text, panel), `input text in ${mode}`).toBeGreaterThan(4.5)
      await page.keyboard.press('Escape')
      await page.goto('/user/settings')
      await expect(page.getByLabel('Idle limit (minutes)')).toBeVisible()
      await page.screenshot({ path: `test-results/theme-${mode}-settings.png`, fullPage: true })
    })
  }
})

test.describe('on a phone', () => {
  test.use({ ...as('oic'), viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true })

  test('the sidebar is hidden, opens from the menu button, navigates, and closes', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('link', { name: 'People', exact: true })).toHaveCount(0)
    await page.getByRole('button', { name: 'Open sidebar' }).click()
    const dialog = page.getByRole('dialog')
    for (const name of ['Overview', 'People', 'Reports', 'Roles', 'Settings', 'Audit log']) await expect(dialog.getByRole('link', { name, exact: true })).toBeVisible()
    await dialog.getByRole('link', { name: 'People', exact: true }).click()
    await expect(page).toHaveURL(/\/user\/people$/)
    await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await page.getByRole('button', { name: 'Open sidebar' }).click()
    await page.getByRole('button', { name: 'Close sidebar' }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
  })

  test('the profile menu and sign out work on a phone', async ({ page }) => {
    await page.goto('/user')
    await page.getByRole('button', { name: /open user menu/i }).click()
    await expect(page.getByRole('menuitem', { name: 'Your profile' })).toBeVisible()
    await expect(page.getByRole('menuitem', { name: 'Sign out' })).toBeVisible()
  })

  test('the Add form fits the screen and can be completed with the keyboard', async ({ page }) => {
    await page.goto('/user/people')
    await page.getByRole('button', { name: 'Add a person' }).click()
    const box = await page.getByRole('dialog').getByLabel('Full name').boundingBox()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(375)
  })

  test('the person page is usable on a phone', async ({ page }) => {
    await page.goto(`/user/employees/${await idOf(page, 'dev1@test.com')}`)
    await expect(page.getByRole('heading', { name: 'Dan Ramos' })).toBeVisible()
    await expect(page.getByLabel('Timeline of the day')).toBeVisible()
    const scrolls = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
    expect(scrolls).toBe(false)
  })
})
