import { apiGet, as, expect, idOf, test } from '../fixtures'

const idle = (page: import('@playwright/test').Page) => page.getByLabel('Idle limit (minutes)')
const consent = (page: import('@playwright/test').Page) => page.getByLabel('Consent version')
const save = (page: import('@playwright/test').Page) => page.getByRole('button', { name: 'Save settings' })

test.describe.serial('organization settings as the admin', () => {
  test.use(as('oic'))

  test('READ: shows the current organization settings, nothing to save yet', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(page.getByRole('heading', { name: 'Organization settings' })).toBeVisible()
    await expect(idle(page)).toHaveValue('5')
    await expect(page.getByLabel('Full window titles')).toBeChecked()
    await expect(page.getByLabel('Organization timezone')).toHaveValue('Asia/Manila')
    await expect(page.getByLabel('Minimum desktop app version')).toHaveCount(0) // the platform's setting now
    await expect(consent(page)).toHaveValue('1')
    await expect(save(page)).toBeDisabled()
    await expect(page.getByRole('button', { name: 'Discard changes' })).toBeDisabled()
  })

  test('the form refuses bad values with a message next to each field', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(idle(page)).toHaveValue('5')
    await idle(page).fill('0')
    await idle(page).blur()
    await expect(page.getByText('Choose between 1 and 30 minutes.')).toBeVisible()
    await idle(page).fill('31')
    await expect(page.getByText('Choose between 1 and 30 minutes.')).toBeVisible()
    await idle(page).fill('2.5')
    await expect(page.getByText('Use a whole number of minutes.')).toBeVisible()
    await idle(page).fill('')
    await idle(page).blur()
    await expect(page.getByText('Enter the idle limit in minutes.')).toBeVisible()
    await idle(page).fill('5')

    await consent(page).fill('0')
    await consent(page).blur()
    await expect(page.getByText('It cannot go below the current version (1).')).toBeVisible()
  })

  test('nothing is sent when the form is invalid', async ({ page }) => {
    let put = false
    page.on('request', (r) => {
      if (r.method() === 'PUT' && r.url().includes('/admin/settings')) put = true
    })
    await page.goto('/user/settings')
    await expect(idle(page)).toHaveValue('5')
    await idle(page).fill('99')
    await save(page).click()
    await expect(page.getByText('Choose between 1 and 30 minutes.')).toBeVisible()
    expect(put).toBe(false)
  })

  test('Discard puts every field back', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(idle(page)).toHaveValue('5')
    await idle(page).fill('12')
    await page.getByLabel('App names only').check()
    await expect(save(page)).toBeEnabled()
    await page.getByRole('button', { name: 'Discard changes' }).click()
    await expect(idle(page)).toHaveValue('5')
    await expect(page.getByLabel('Full window titles')).toBeChecked()
    await expect(save(page)).toBeDisabled()
  })

  test('UPDATE: changes are saved, confirmed, kept after a reload, and visible to the API', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(idle(page)).toHaveValue('5')
    await idle(page).fill('10')
    await page.getByLabel('App names only').check()
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
    await expect(save(page)).toBeDisabled()
    await page.reload()
    await expect(idle(page)).toHaveValue('10')
    await expect(page.getByLabel('App names only')).toBeChecked()
    const api = await (await apiGet(page, '/admin/settings')).json()
    expect(api).toMatchObject({ idleThresholdSeconds: 600, windowTitleMode: 'app_only' })
  })

  test('UPDATE: a new timezone applies to every time shown straight away', async ({ page }) => {
    await page.goto('/user')
    const before = await page.getByRole('row').filter({ hasText: 'Cara Sy' }).textContent()
    await page.goto('/user/settings')
    await page.getByLabel('Organization timezone').selectOption('Pacific/Auckland')
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
    await page.goto('/user/profile')
    await expect(page.getByText('Pacific/Auckland')).toBeVisible()
    await page.goto('/user')
    const after = await page.getByRole('row').filter({ hasText: 'Cara Sy' }).textContent()
    expect(after).not.toBe(before)
    await page.goto('/user/settings')
    await page.getByLabel('Organization timezone').selectOption('Asia/Manila')
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
  })

  test('UPDATE: the values are put back', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(idle(page)).toHaveValue('10')
    await idle(page).fill('5')
    await page.getByLabel('Full window titles').check()
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
  })

  test('the API refuses values the form would never send', async ({ page }) => {
    await page.goto('/user/settings')
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const headers = { Accept: 'application/json', Origin: 'http://localhost:3101', Referer: 'http://localhost:3101/', 'X-XSRF-TOKEN': xsrf }
    for (const bad of [{ idleThresholdSeconds: 5 }, { timezone: 'Mars/Base' }, { windowTitleMode: 'everything' }, { consentVersion: 0 }]) {
      const res = await page.request.put('/api/v1/admin/settings', { headers, data: bad })
      expect(res.status(), JSON.stringify(bad)).toBe(422)
    }
  })

  test('raising the consent version shows the warning, and saving it is one-way', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(consent(page)).toHaveValue('1')
    await expect(page.getByText('Raise this only when the tracking notice changes. It can never go down.')).toBeVisible()
    await consent(page).fill('2')
    await expect(page.getByText('Raising the consent version makes every person accept the tracking notice again')).toBeVisible()
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
    await expect(consent(page)).toHaveValue('2')
    await consent(page).fill('1')
    await consent(page).blur()
    await expect(page.getByText('It cannot go below the current version (2).')).toBeVisible()
  })

  test('every change is in the audit log', async ({ page }) => {
    await page.goto('/user/audit')
    await expect(page.locator('td', { hasText: 'Changed organization settings' }).first()).toBeVisible()
    const id = await idOf(page, 'oic@test.com')
    expect(id).toBeTruthy()
  })
})

test.describe('settings need their own permission', () => {
  for (const who of ['pm1', 'tl1'] as const) {
    test.describe(who, () => {
      test.use(as(who))

      test('the page is closed and the API refuses', async ({ page, allow }) => {
        allow(/\/api\/v1\/admin\/(settings|audit) 403/)
        await page.goto('/user/settings')
        await expect(page).not.toHaveURL(/\/user\/settings$/)
        await page.goto('/user/audit')
        await expect(page).not.toHaveURL(/\/user\/audit$/)
        expect((await apiGet(page, '/admin/settings')).status()).toBe(403)
        expect((await apiGet(page, '/admin/audit')).status()).toBe(403)
      })
    })
  }
})
