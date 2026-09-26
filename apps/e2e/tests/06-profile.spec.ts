import { ACCOUNTS, PASSWORD, apiGet, expect, loginAs, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { waitForMail } from '../helpers/mail'

// Tess Lim (a team leader nobody else's test signs in as) is the account whose profile is edited.
const WHO = ACCOUNTS.tl3
const NEW_PASSWORD = 'tess-new-password-7'
const NEW_EMAIL = 'tess.new@test.com'

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
    await expect(page.getByText('Your role is set by the people above you in your organization.')).toBeVisible()
    await expect(page.getByText('Demo Office', { exact: true }).first()).toBeVisible()
  })

  test('the user menu leads to the profile, and the sidebar has no duplicate link', async ({ page }) => {
    await loginAs(page, WHO)
    await expect(page.getByRole('link', { name: 'Your profile' })).toHaveCount(0)
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

  test('UPDATE email: Change email is off until something is typed, and the address and password are checked', async ({ page }) => {
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await expect(page.getByRole('heading', { name: 'Email address' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Change email' })).toBeDisabled()
    await page.getByLabel('New email address').fill('not-an-email')
    await page.getByLabel('New email address').blur()
    await expect(page.getByText('Enter a valid email address.')).toBeVisible()
    await page.getByLabel('New email address').fill(NEW_EMAIL)
    await page.getByRole('button', { name: 'Change email' }).click()
    await expect(page.getByText('Enter your current password.')).toBeVisible()
    // typing the address they already have does not enable it (case does not matter)
    await page.getByLabel('New email address').fill(WHO.toUpperCase())
    await expect(page.getByRole('button', { name: 'Change email' })).toBeDisabled()
  })

  test('UPDATE email: a wrong password is refused and nothing changes', async ({ page, allow }) => {
    allow(/PUT \/api\/v1\/me\/email 422/)
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('New email address').fill(NEW_EMAIL)
    await page.getByLabel('Your password').fill('not the password')
    await page.getByRole('button', { name: 'Change email' }).click()
    await expect(page.getByText('Your current password is not right.')).toBeVisible()
    await expect(page.getByText('Your email address was changed.')).toHaveCount(0)
    expect((await (await apiGet(page, '/me')).json()).email).toBe(WHO)
  })

  test('UPDATE email: an address someone else already uses is refused', async ({ page, allow }) => {
    allow(/PUT \/api\/v1\/me\/email 409/)
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('New email address').fill(ACCOUNTS.oic)
    await page.getByLabel('Your password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Change email' }).click()
    await expect(page.getByText('A user with this email already exists.')).toBeVisible()
    expect((await (await apiGet(page, '/me')).json()).email).toBe(WHO)
  })

  test('UPDATE email: a valid change is shown, the old address is told, and only the new address signs in', async ({ page, browser, allow }) => {
    allow(/POST \/auth\/login 401/)
    await loginAs(page, WHO)
    await page.goto('/user/profile')
    await page.getByLabel('New email address').fill(NEW_EMAIL)
    await page.getByLabel('Your password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Change email' }).click()
    await expect(page.getByText('Your email address was changed.')).toBeVisible()
    await expect(page.getByText(NEW_EMAIL, { exact: true })).toBeVisible()
    await expect(page.getByLabel('New email address')).toHaveValue('')
    await expect(page.getByLabel('Your password')).toHaveValue('')
    await expect(page.getByRole('button', { name: 'Change email' })).toBeDisabled()

    // the notice went to the OLD address and names the new one
    const log = await waitForMail([NEW_EMAIL])
    expect(log).toMatch(new RegExp(`To:[^\\n]*${WHO.replace('.', '\\.')}`))

    const other = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: { cookies: [], origins: [] } })
    const fresh = await other.newPage()
    await fresh.goto('/')
    await fresh.getByLabel('Email address').fill(WHO)
    await fresh.getByLabel('Password').fill(PASSWORD)
    await fresh.getByRole('button', { name: 'Sign in' }).click()
    await expect(fresh.getByText(/incorrect email or password/i)).toBeVisible()
    await fresh.getByLabel('Email address').fill(NEW_EMAIL)
    await fresh.getByRole('button', { name: 'Sign in' }).click()
    await expect(fresh).toHaveURL(/\/user$/)
    await other.close()
  })

  test('UPDATE email: it is put back, and the OIC\'s audit log shows both changes', async ({ page, browser }) => {
    await loginAs(page, NEW_EMAIL)
    await page.goto('/user/profile')
    await page.getByLabel('New email address').fill(WHO)
    await page.getByLabel('Your password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Change email' }).click()
    await expect(page.getByText('Your email address was changed.')).toBeVisible()
    expect((await (await apiGet(page, '/me')).json()).email).toBe(WHO)

    const oic = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: '.auth/oic.json' })
    const audit = await oic.newPage()
    await audit.goto('/user/audit')
    await expect(audit.getByRole('row').filter({ hasText: 'Changed their email' }).filter({ hasText: `${WHO} → ${NEW_EMAIL}` })).toBeVisible()
    await expect(audit.getByRole('row').filter({ hasText: 'Changed their email' }).filter({ hasText: `${NEW_EMAIL} → ${WHO}` })).toBeVisible()
    await oic.close()
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
    await expect(page.getByText('Admin', { exact: true }).first()).toBeVisible() // the name of the role the demo organization gave its admin
  })

  test('signed-out visitors are sent to sign in', async ({ page }) => {
    await page.goto('/user/profile')
    await expect(page).toHaveURL(/\/$/)
  })
})
