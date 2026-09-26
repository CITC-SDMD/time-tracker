import { as, expect, loginAs, otherPerson, PASSWORD, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { totp } from '../helpers/totp'

// Two-factor sign-in (docs/SECURITY_REVIEW.md): a person turns it on from their profile with an authenticator app, and
// from then on the password alone does not sign them in. The test plays the app with the secret the page shows.
const EMAIL = 'tl3@test.com'

test.beforeEach(() => artisan('cache:clear'))
// a run that stopped half way must not leave it on for the next one
test.beforeAll(() => artisan('tracker:reset-two-factor', EMAIL))
test.afterAll(() => artisan('tracker:reset-two-factor', EMAIL))

test.describe.serial('two-factor sign-in', () => {
  test.use(as('tl3'))

  let secret = ''

  test('a person turns it on from their profile and gets recovery codes', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/me\/two-factor(\/confirm)? 422/)
    await page.goto('/user/profile')
    const card = page.getByRole('heading', { name: 'Two-factor sign-in' }).locator('xpath=..')

    // a wrong password is refused and nothing starts
    await card.getByLabel('Password to confirm').fill('not-my-password')
    await card.getByRole('button', { name: 'Set up two-factor sign-in' }).click()
    await expect(card.getByText('Your password is not right.')).toBeVisible()

    await card.getByLabel('Password to confirm').fill(PASSWORD)
    await card.getByRole('button', { name: 'Set up two-factor sign-in' }).click()
    await expect(card.getByAltText('QR code for your authenticator app')).toBeVisible()
    secret = (await card.locator('p.font-mono').innerText()).trim()
    expect(secret).toMatch(/^[A-Z2-7]{32}$/)

    // a wrong code does not turn it on
    await card.getByLabel('6-digit code').fill('000000')
    await card.getByRole('button', { name: 'Turn on' }).click()
    await expect(card.getByText('That code is not right.')).toBeVisible()

    await card.getByLabel('6-digit code').fill(totp(secret))
    await card.getByRole('button', { name: 'Turn on' }).click()
    await expect(card.getByText('Save these recovery codes')).toBeVisible()
    await expect(card.locator('ul.font-mono li')).toHaveCount(8)
    await card.getByRole('button', { name: 'I have saved them' }).click()
    await expect(card.getByText('Two-factor sign-in is on. 8 recovery codes are left.')).toBeVisible()
  })

  test('the password alone no longer signs in: the code is asked for, a wrong one is refused', async ({ browser, allow }) => {
    allow(/POST \/auth\/two-factor 422/)
    const page = await otherPerson(browser)
    await page.goto('/')
    await page.getByLabel('Email address').fill(EMAIL)
    await page.getByLabel('Password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page.getByLabel('Code')).toBeVisible()
    await page.getByLabel('Code').fill('000000')
    await page.getByRole('button', { name: 'Verify' }).click()
    await expect(page.getByText('That code is not right.')).toBeVisible()
    await expect(page).toHaveURL(/\/$/)

    // the step after the one used to turn it on
    await page.getByLabel('Code').fill(totp(secret, 1))
    await page.getByRole('button', { name: 'Verify' }).click()
    await expect(page).toHaveURL(/\/user/)
    await page.context().close()
  })

  test('the admin resets it for someone who lost their phone, and the password alone works again', async ({ browser }) => {
    const admin = await otherPerson(browser, 'oic')
    await admin.goto('/user/people')
    await admin.getByRole('link', { name: 'Tess Lim' }).click()
    await expect(admin.getByRole('heading', { name: 'Tess Lim' })).toBeVisible()

    await admin.getByRole('button', { name: 'Reset two-factor' }).click()
    await admin.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()
    await admin.getByRole('button', { name: 'Reset two-factor' }).click()
    await admin.getByRole('dialog').getByRole('button', { name: 'Reset', exact: true }).click()
    await expect(admin.getByText('Two-factor sign-in was reset for Tess Lim.')).toBeVisible()
    await admin.context().close()

    const page = await otherPerson(browser)
    await loginAs(page, EMAIL)
    await expect(page).toHaveURL(/\/user/)
    await page.context().close()
  })
})
