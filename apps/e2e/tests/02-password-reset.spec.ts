import { ACCOUNTS, PASSWORD, expect, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { mailLinkCount, nextMailLink } from '../helpers/mail'

// Sam Ong (an individual contributor) is the account used to be reset; nothing else uses it.
const WHO = 'sa1@test.com'
const NEW_PASSWORD = 'a-brand-new-pass-1'

test.beforeEach(() => artisan('cache:clear'))

test.describe.serial('forgot and reset password', () => {
  let link = ''

  test('the forgot page validates the email', async ({ page }) => {
    await page.goto('/forgot-password')
    await page.getByRole('button', { name: 'Send the link' }).click()
    await expect(page.getByText('Enter your email address.')).toBeVisible()
    await page.getByLabel('Email').fill('nope')
    await page.getByRole('button', { name: 'Send the link' }).click()
    await expect(page.getByText('Enter a valid email address.')).toBeVisible()
  })

  test('an unknown email gets the same answer and no mail is sent', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/forgot-password')
    await page.getByLabel('Email').fill('nobody@test.com')
    await page.getByRole('button', { name: 'Send the link' }).click()
    await expect(page.getByText('If that email has an account, we sent a link to it.')).toBeVisible()
    await page.waitForTimeout(1000)
    expect(mailLinkCount()).toBe(before)
  })

  test('a known email gets the same answer and a link by mail', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/forgot-password')
    await page.getByLabel('Email').fill(WHO)
    await page.getByRole('button', { name: 'Send the link' }).click()
    await expect(page.getByText('If that email has an account, we sent a link to it.')).toBeVisible()
    link = await nextMailLink(before)
    expect(link).toContain('/reset-password?link=')
  })

  test('the reset page names the account and validates both fields', async ({ page }) => {
    await page.goto(link)
    await expect(page.getByRole('heading', { name: 'Choose your password' })).toBeVisible()
    await expect(page.getByText(`For ${WHO}`)).toBeVisible()
    await page.getByRole('button', { name: 'Set password' }).click()
    await expect(page.getByText('Choose a password.')).toBeVisible()
    await expect(page.getByText('Type the password again.')).toBeVisible()
    await page.getByLabel('New password (at least 10 characters)').fill('short')
    await page.getByLabel('Type it again').fill('different')
    await page.getByRole('button', { name: 'Set password' }).click()
    await expect(page.getByText('Use at least 10 characters.')).toBeVisible()
    await expect(page.getByText('The two passwords are not the same.')).toBeVisible()
  })

  test('a valid new password is saved', async ({ page }) => {
    await page.goto(link)
    await page.getByLabel('New password (at least 10 characters)').fill(NEW_PASSWORD)
    await page.getByLabel('Type it again').fill(NEW_PASSWORD)
    await page.getByRole('button', { name: 'Set password' }).click()
    await expect(page.getByText('Your password is set.')).toBeVisible()
  })

  test('the same link cannot be used twice', async ({ page, allow }) => {
    allow(/POST \/auth\/reset-password 4\d\d/)
    await page.goto(link)
    await page.getByLabel('New password (at least 10 characters)').fill('another-pass-12345')
    await page.getByLabel('Type it again').fill('another-pass-12345')
    await page.getByRole('button', { name: 'Set password' }).click()
    await expect(page.getByText(/invalid|expired/i).first()).toBeVisible()
  })

  test('the old password no longer works but the new one does (API check)', async ({ page, allow }) => {
    allow(/POST \/auth\/login (401|403)/)
    // Sam is a contributor, so the dashboard refuses both with 403 "managers only" after checking
    // the password: a wrong password is 401 and a right one is 403, which tells the two apart.
    await page.goto('/')
    await page.getByLabel('Email address').fill(WHO)
    await page.getByLabel('Password').fill(PASSWORD)
    const old = page.waitForResponse(r => r.url().includes('/auth/login'))
    await page.getByRole('button', { name: 'Sign in' }).click()
    expect((await old).status()).toBe(401)
    await page.getByLabel('Password').fill(NEW_PASSWORD)
    const fresh = page.waitForResponse(r => r.url().includes('/auth/login'))
    await page.getByRole('button', { name: 'Sign in' }).click()
    expect((await fresh).status()).toBe(403)
  })
})

test.describe('reset links that cannot work', () => {
  test('a page opened without its link says so and offers a new one', async ({ page }) => {
    await page.goto('/reset-password')
    await expect(page.getByText('This link is incomplete.')).toBeVisible()
    await expect(page.getByRole('link', { name: 'ask for a new one' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Set password' })).toHaveCount(0)
  })

  test('a garbled link value is treated as incomplete', async ({ page }) => {
    await page.goto('/reset-password?link=%%%not-base64')
    await expect(page.getByText('This link is incomplete.')).toBeVisible()
  })

  test('a well-formed link with a wrong token is refused by the server', async ({ page, allow }) => {
    allow(/POST \/auth\/reset-password 4\d\d/)
    const value = Buffer.from(JSON.stringify({ token: 'wrong', email: ACCOUNTS.tl1 })).toString('base64url')
    await page.goto(`/reset-password?link=${value}`)
    await page.getByLabel('New password (at least 10 characters)').fill('valid-password-1')
    await page.getByLabel('Type it again').fill('valid-password-1')
    await page.getByRole('button', { name: 'Set password' }).click()
    await expect(page.getByText(/invalid|expired/i).first()).toBeVisible()
  })
})
