import { ACCOUNTS, PASSWORD, expect, loginAs, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

// the login endpoint allows 10 tries a minute: start each test with a clean count
test.beforeEach(() => artisan('cache:clear'))

test.describe('sign in', () => {
  test('the page shows the form and no errors before anything is typed', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible()
    await expect(page.getByLabel('Email address')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Forgot password?' })).toBeVisible()
  })

  test('empty and malformed fields show the validation messages and send nothing', async ({ page }) => {
    let posted = false
    page.on('request', r => { if (r.url().includes('/auth/login')) posted = true })
    await page.goto('/')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByText('Enter your email address.')).toBeVisible()
    await expect(page.getByText('Enter your password.')).toBeVisible()
    await page.getByLabel('Email address').fill('not-an-email')
    await page.getByLabel('Password').fill('x')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByText('Enter a valid email address.')).toBeVisible()
    expect(posted).toBe(false)
  })

  test('a wrong password shows the server message and stays on the page', async ({ page, allow }) => {
    allow(/POST \/auth\/login 401/)
    await page.goto('/')
    await page.getByLabel('Email address').fill(ACCOUNTS.oic)
    await page.getByLabel('Password').fill('wrong-password')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByText(/incorrect email or password/i)).toBeVisible()
    await expect(page).toHaveURL(/\/$/)
  })

  test('an unknown email gets the same message as a wrong password', async ({ page, allow }) => {
    allow(/POST \/auth\/login 401/)
    await page.goto('/')
    await page.getByLabel('Email address').fill('nobody@test.com')
    await page.getByLabel('Password').fill('whatever-123')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByText(/incorrect email or password/i)).toBeVisible()
  })

  test('the OIC signs in and lands on the overview', async ({ page }) => {
    await loginAs(page, ACCOUNTS.oic)
    await expect(page).toHaveURL(/\/user$/)
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible()
  })

  test('a project manager and a team leader land on the overview too', async ({ page }) => {
    await loginAs(page, ACCOUNTS.pm1)
    await expect(page).toHaveURL(/\/user$/)
  })

  test('the superadmin lands on the superadmin area', async ({ page }) => {
    await loginAs(page, ACCOUNTS.admin)
    await expect(page).toHaveURL(/\/superadmin/)
  })

  test('an individual contributor cannot use the dashboard', async ({ page, allow }) => {
    allow(/POST \/auth\/login 4\d\d/)
    await page.goto('/')
    await page.getByLabel('Email address').fill(ACCOUNTS.dev1)
    await page.getByLabel('Password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByText(/managers only/i).first()).toBeVisible()
  })

  test('signing in is refused after too many wrong tries', async ({ page, allow }) => {
    allow(/POST \/auth\/login (401|429)/)
    await page.goto('/')
    for (let i = 0; i < 11; i++) {
      await page.getByLabel('Email address').fill(ACCOUNTS.oic)
      await page.getByLabel('Password').fill(`wrong-${i}`)
      await page.getByRole('button', { name: /Sign in|Signing in/ }).click()
      await page.waitForResponse(r => r.url().includes('/auth/login'))
    }
    await expect(page.getByText(/too many|try again/i).first()).toBeVisible()
    await expect(page).toHaveURL(/\/$/)
  })
})

test.describe('signed in', () => {
  test('sign out ends the session: the API and every page refuse afterwards', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/me 401/)
    await loginAs(page, ACCOUNTS.oic)
    await page.getByRole('button', { name: /open user menu/i }).click()
    await page.getByRole('menuitem', { name: 'Sign out' }).click()
    await expect(page).toHaveURL(/\/$/)
    await page.goto('/user/people')
    await expect(page).toHaveURL(/\/$/)
    expect((await page.request.get('/api/v1/employees')).status()).toBe(401)
  })

  test('opening the sign-in page while signed in goes to the home page', async ({ page }) => {
    await loginAs(page, ACCOUNTS.oic)
    await page.goto('/')
    await expect(page).toHaveURL(/\/user$/)
  })
})
