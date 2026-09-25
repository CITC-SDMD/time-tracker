import { ACCOUNTS, PASSWORD, apiGet, expect, loginAs, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

// Tess Lim (a team leader nobody else's test signs in as) is the account whose profile is edited.
const WHO = ACCOUNTS.tl3
const NEW_PASSWORD = 'tess-new-password-7'

test.beforeEach(() => artisan('cache:clear'))

test.describe.serial('profile', () => {
  test('READ: shows the person\'s own details, read-only', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await expect(page.getByRole('heading', { name: 'Your profile' })).toBeVisible()
    await expect(page.getByLabel('Full name')).toHaveValue('Tess Lim')
    await expect(page.getByText(WHO, { exact: true })).toBeVisible()
    await expect(page.getByText('Team Leader', { exact: true })).toBeVisible()
    await expect(page.getByText('Pedro Santos', { exact: true })).toBeVisible()
    await expect(page.getByText('Asia/Manila')).toBeVisible()
    await expect(page.getByText('Your email and role are set by your manager.')).toBeVisible()
  })

  test('the sidebar and the menu both lead to the profile', async ({ page }) => {
    await loginAs(page, WHO)
    await page.getByRole('link', { name: 'Your profile' }).click()
    await expect(page).toHaveURL(/\/user\/profile$/)
    await page.goto('/user')
    await page.getByRole('button', { name: /open user menu/i }).click()
    await page.getByRole('menuitem', { name: 'Your profile' }).click()
    await expect(page).toHaveURL(/\/user\/profile$/)
  })

  test('UPDATE name: Save is off until something changes, and an empty name is refused', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await expect(page.getByRole('button', { name: 'Save name' })).toBeDisabled()
    await page.getByLabel('Full name').fill('   ')
    await expect(page.getByRole('button', { name: 'Save name' })).toBeDisabled()
    await page.getByLabel('Full name').fill('')
    await page.getByLabel('Full name').blur()
    await expect(page.getByText('Enter your name.')).toBeVisible()
    await page.getByLabel('Full name').fill('x'.repeat(256))
    await page.getByLabel('Full name').blur()
    await expect(page.getByText('Use at most 255 characters.')).toBeVisible()
  })

  test('UPDATE name: a new name is trimmed, saved, shown in the menu, and kept after a reload', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('Full name').fill('  Tess Lim-Reyes  ')
    await page.getByRole('button', { name: 'Save name' }).click()
    await expect(page.getByText('Your name was saved.')).toBeVisible()
    await expect(page.getByLabel('Full name')).toHaveValue('Tess Lim-Reyes')
    await expect(page.getByRole('button', { name: /open user menu/i })).toContainText('Tess Lim-Reyes')
    await page.reload()
    await expect(page.getByLabel('Full name')).toHaveValue('Tess Lim-Reyes')
    // the team sees the new name too
    const me = await (await apiGet(page, '/me')).json()
    expect(me.name).toBe('Tess Lim-Reyes')
  })

  test('UPDATE name: the change is in the OIC\'s people list', async ({ browser }) => {
    const oic = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: '.auth/oic.json' })
    const page = await oic.newPage()
    await page.goto('/user/people')
    await expect(page.getByRole('link', { name: 'Tess Lim-Reyes', exact: true })).toBeVisible()
    await oic.close()
  })

  test('UPDATE name: it is put back', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('Full name').fill('Tess Lim')
    await page.getByRole('button', { name: 'Save name' }).click()
    await expect(page.getByText('Your name was saved.')).toBeVisible()
  })

  test('UPDATE password: every field is required and the rules are explained', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByText('Enter your current password.')).toBeVisible()
    await expect(page.getByText('Enter a new password.')).toBeVisible()
    await expect(page.getByText('Repeat the new password.')).toBeVisible()
    await page.getByLabel('Current password').fill(PASSWORD)
    await page.getByLabel('New password', { exact: true }).fill('short')
    await page.getByLabel('Repeat the new password').fill('different')
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByText('Use at least 10 characters.')).toBeVisible()
    await expect(page.getByText('The two passwords are not the same.')).toBeVisible()
  })

  test('UPDATE password: a wrong current password is refused and nothing changes', async ({ page, allow }) => {
    allow(/PUT \/api\/v1\/me\/password 4\d\d/)
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('Current password').fill('not-my-password')
    await page.getByLabel('New password', { exact: true }).fill(NEW_PASSWORD)
    await page.getByLabel('Repeat the new password').fill(NEW_PASSWORD)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByText(/current password/i).last()).toBeVisible()
    await expect(page.getByText('Your password was changed.')).toHaveCount(0)
  })

  test('UPDATE password: a valid change works, clears the form, and only the new password signs in', async ({ page, browser, allow }) => {
    allow(/POST \/auth\/login 401/)
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('Current password').fill(PASSWORD)
    await page.getByLabel('New password', { exact: true }).fill(NEW_PASSWORD)
    await page.getByLabel('Repeat the new password').fill(NEW_PASSWORD)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByText(/Your password was changed\./)).toBeVisible()
    await expect(page.getByLabel('Current password')).toHaveValue('')
    await expect(page.getByLabel('New password', { exact: true })).toHaveValue('')

    const other = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: { cookies: [], origins: [] } })
    const fresh = await other.newPage()
    await fresh.goto('/')
    await fresh.getByLabel('Email address').fill(WHO)
    await fresh.getByLabel('Password').fill(PASSWORD)
    await fresh.getByRole('button', { name: 'Sign in' }).click()
    await expect(fresh.getByText(/incorrect email or password/i)).toBeVisible()
    await fresh.getByLabel('Password').fill(NEW_PASSWORD)
    await fresh.getByRole('button', { name: 'Sign in' }).click()
    await expect(fresh).toHaveURL(/\/user$/)
    await other.close()
  })

  test('UPDATE password: choosing the same password again is refused by the server', async ({ page, allow }) => {
    allow(/PUT \/api\/v1\/me\/password 4\d\d/)
    await loginAs(page, WHO, NEW_PASSWORD)
    await page.goto('/user/profile')
    await page.getByLabel('Current password').fill(NEW_PASSWORD)
    await page.getByLabel('New password', { exact: true }).fill(NEW_PASSWORD)
    await page.getByLabel('Repeat the new password').fill(NEW_PASSWORD)
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page.getByText(/different|same|new password/i).last()).toBeVisible()
    await expect(page.getByText('Your password was changed.')).toHaveCount(0)
  })

  test('the audit log records the password change without the password', async ({ browser }) => {
    const oic = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: '.auth/oic.json' })
    const page = await oic.newPage()
    await page.goto('/user/audit')
    await expect(page.locator('td', { hasText: 'Changed their password' }).first()).toBeVisible()
    await expect(page.locator('body')).not.toContainText(NEW_PASSWORD)
    await oic.close()
  })
})

test.describe('profile for other roles', () => {
  test('the OIC has no manager', async ({ page }) => {
    await loginAs(page, ACCOUNTS.oic)
    await page.goto('/user/profile')
    await expect(page.getByText('No one', { exact: true })).toBeVisible()
    await expect(page.getByText('OIC', { exact: true }).first()).toBeVisible()
  })

  test('signed-out visitors are sent to sign in', async ({ page }) => {
    await page.goto('/user/profile')
    await expect(page).toHaveURL(/\/$/)
  })
})
