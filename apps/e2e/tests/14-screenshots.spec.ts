import AxeBuilder from '@axe-core/playwright'
import type { APIRequestContext, Page } from '@playwright/test'
import { DASH_HEADERS, PASSWORD, apiGet, as, expect, idOf, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

// Screenshots (docs phase 10) from the dashboard's side: settings, the gallery, who may see what, and an
// upload from a desktop app. The demo office already has six generated pictures each for Dan Ramos and
// Dana Uy, and the files live on the local disk (SCREENSHOT_DISK=local); no real S3 server is used here.

const interval = (page: Page) => page.getByLabel("Take a screenshot of each person's main screen")
const consent = (page: Page) => page.getByLabel('Consent version')
const save = (page: Page) => page.getByRole('button', { name: 'Save settings' })
const thumbs = (page: Page) => page.getByRole('list', { name: 'Screenshots of the day' }).getByRole('button')

async function personPage(page: Page, email = 'dev1@test.com') {
  await page.goto(`/user/employees/${await idOf(page, email)}`)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
}

/** A real JPEG made by the browser itself, so no image library is needed in the tests. */
async function jpegFrom(page: Page): Promise<Buffer> {
  const base64 = await page.evaluate(() => {
    const canvas = document.createElement('canvas')
    canvas.width = 64
    canvas.height = 36
    const ctx = canvas.getContext('2d')!
    ctx.fillStyle = '#b45309'
    ctx.fillRect(0, 0, 64, 36)
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(10, 10, 30, 8)
    return canvas.toDataURL('image/jpeg', 0.8).split(',')[1]!
  })
  return Buffer.from(base64, 'base64')
}

/** The desktop app's login: a bearer token for one person. */
async function agentToken(request: APIRequestContext, email: string): Promise<string> {
  const res = await request.post('/api/v1/auth/login', {
    headers: { Accept: 'application/json', 'X-Device-Id': '11111111-1111-4111-8111-111111111111' },
    data: { email, password: PASSWORD },
  })
  expect(res.ok(), 'agent login').toBe(true)
  return (await res.json()).token
}

test.beforeEach(() => artisan('cache:clear'))

test.describe.serial('screenshots as the OIC', () => {
  test.use(as('oic'))

  test('the settings have a Screenshots section that starts switched off', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(page.getByRole('heading', { name: 'Screenshots' })).toBeVisible()
    await expect(interval(page)).toHaveValue('0')
    await expect(page.getByLabel('At a random moment in each block')).toBeDisabled()
    await expect(page.getByText('Space used so far:')).toBeVisible()
    await expect(save(page)).toBeDisabled()
  })

  test('the gallery shows the day\'s pictures even while screenshots are off', async ({ page }) => {
    await personPage(page)
    await expect(page.getByRole('heading', { name: 'Screenshots' })).toBeVisible()
    await expect(thumbs(page).first()).toBeVisible()
  })

  test('a person with no pictures and screenshots off has no Screenshots section', async ({ page }) => {
    await personPage(page, 'tl1@test.com')
    await expect(page.getByRole('heading', { name: 'Timeline' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Screenshots' })).toHaveCount(0)
  })

  test('turning screenshots on raises the consent version for you, and it is undone if you change your mind', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(interval(page)).toHaveValue('0')
    const before = Number(await consent(page).inputValue())
    await interval(page).selectOption('10')
    await expect(consent(page)).toHaveValue(String(before + 1))
    await expect(page.getByText('Raising the consent version makes every person accept the tracking notice again')).toBeVisible()
    await expect(page.getByLabel('At a random moment in each block')).toBeEnabled()
    await interval(page).selectOption('0')
    await expect(consent(page)).toHaveValue(String(before))
    await expect(save(page)).toBeDisabled()
  })

  test('the API refuses to turn screenshots on without a new consent version', async ({ page }) => {
    await page.goto('/user/settings')
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const res = await page.request.put('/api/v1/admin/settings', { headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }, data: { screenshotIntervalMinutes: 10 } })
    expect(res.status()).toBe(422)
    expect((await res.json()).error.code).toBe('SCREENSHOTS_NEED_CONSENT')
    const bad = await page.request.put('/api/v1/admin/settings', { headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }, data: { screenshotIntervalMinutes: 7, consentVersion: 99 } })
    expect(bad.status()).toBe(422)
  })

  test('UPDATE: turning them on is saved, kept after a reload, and written to the audit log', async ({ page }) => {
    await page.goto('/user/settings')
    await expect(interval(page)).toHaveValue('0')
    await interval(page).selectOption('10')
    await page.getByLabel('At a random moment in each block').check()
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
    await page.reload()
    await expect(interval(page)).toHaveValue('10')
    await expect(page.getByLabel('At a random moment in each block')).toBeChecked()
    const stored = await (await apiGet(page, '/admin/settings')).json()
    expect(stored).toMatchObject({ screenshotIntervalMinutes: 10, screenshotRandom: true })
    await page.goto('/user/audit')
    await expect(page.locator('td', { hasText: 'screenshot interval (minutes) → 10' }).first()).toBeVisible()
  })

  test('with screenshots on, a person with no pictures shows the empty message', async ({ page }) => {
    await personPage(page, 'tl1@test.com')
    await expect(page.getByRole('heading', { name: 'Screenshots' })).toBeVisible()
    await expect(page.getByText('No screenshots on this day')).toBeVisible()
  })

  test('READ: the gallery shows thumbnails that really load, with the time under each', async ({ page }) => {
    await personPage(page)
    const first = thumbs(page).first()
    await expect(first).toBeVisible()
    const images = page.getByRole('list', { name: 'Screenshots of the day' }).locator('img')
    await expect.poll(async () => images.evaluateAll(list => list.every(i => (i as HTMLImageElement).complete && (i as HTMLImageElement).naturalWidth > 0))).toBe(true)
    await expect(images.first()).toHaveAttribute('alt', /^Screen of Dan Ramos at \d\d:\d\d$/)
    await expect(first).toContainText(/\d\d:\d\d/)
    // oldest first
    const times = await page.getByRole('list', { name: 'Screenshots of the day' }).locator('li span').allTextContents()
    expect([...times].sort()).toEqual(times)
  })

  test('the viewer opens a picture, moves with the buttons and the arrow keys, and gives focus back on Escape', async ({ page }) => {
    await personPage(page)
    await expect(thumbs(page).first()).toBeVisible()
    const count = await thumbs(page).count()
    expect(count).toBeGreaterThan(2)
    const opener = thumbs(page).nth(1)
    await opener.focus()
    await page.keyboard.press('Enter')
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(`2 of ${count}`)).toBeVisible()
    await expect(dialog.getByRole('heading', { name: /^Dan Ramos, / })).toBeVisible()
    const image = dialog.locator('img')
    await expect.poll(async () => image.evaluate(i => (i as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
    await dialog.getByRole('button', { name: 'Next' }).click()
    await expect(dialog.getByText(`3 of ${count}`)).toBeVisible()
    await page.keyboard.press('ArrowLeft')
    await page.keyboard.press('ArrowLeft')
    await expect(dialog.getByText(`1 of ${count}`)).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Previous' })).toBeDisabled()
    await page.keyboard.press('ArrowLeft')
    await expect(dialog.getByText(`1 of ${count}`)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(opener).toBeFocused()
  })

  test('the last picture cannot go Next, and Close works', async ({ page }) => {
    await personPage(page)
    await expect(thumbs(page).first()).toBeVisible()
    const count = await thumbs(page).count()
    await thumbs(page).last().click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(`${count} of ${count}`)).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Next' })).toBeDisabled()
    await dialog.getByRole('button', { name: 'Close' }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
  })

  test('a day with no pictures says so, and picking today again brings them back', async ({ page }) => {
    await personPage(page)
    await page.getByLabel('Pick a date').fill('2020-01-15')
    await expect(page.getByText('No screenshots on this day')).toBeVisible()
    await page.getByRole('button', { name: 'Today' }).click()
    await expect(thumbs(page).first()).toBeVisible()
  })

  test('a server error in the gallery is shown and does not hide the rest of the day', async ({ page, allow }) => {
    allow(/GET \/api\/v1\/employees\/\d+\/screenshots 500/)
    await page.route('**/api/v1/employees/*/screenshots**', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"SERVER_ERROR","message":"Something went wrong."}}' }))
    await personPage(page)
    await expect(page.getByText('Something went wrong.')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Timeline' })).toBeVisible()
  })

  test('looking at the gallery is written to the audit log', async ({ page }) => {
    await page.goto('/user/audit')
    // (other people's galleries were opened too, so look for Dan's entry rather than the newest one)
    const viewed = page.locator('tr', { hasText: 'Viewed screenshots' }).filter({ hasText: 'Dan Ramos' }).first()
    await expect(viewed).toBeVisible()
    await expect(viewed).toContainText(/\d{4}-\d\d-\d\d/)
  })

  test('EXPORT-style check: the picture files are private, on the API only', async ({ page, request }) => {
    const id = await idOf(page, 'dev1@test.com')
    const list = await (await apiGet(page, `/employees/${id}/screenshots?day=${new Date().toISOString().slice(0, 10)}`)).json() as { id: string }[]
    // the raw JSON never carries a path, a bucket or a link
    const shot = list[0]
    if (shot) {
      const text = JSON.stringify(list)
      expect(text).not.toMatch(/https?:|conversions|storage|private/)
      const image = await page.request.get(`/api/v1/screenshots/${shot.id}/image`, { headers: DASH_HEADERS })
      expect(image.status()).toBe(200)
      expect(image.headers()['content-type']).toBe('image/jpeg')
      expect(image.headers()['cache-control']).toContain('private')
    }
    // no public storage link and no way to fetch a file by path
    for (const path of ['/storage/screenshots/x.jpg', '/storage/1/2026/09/26/x/x.jpg']) {
      expect([403, 404]).toContain((await request.get(`http://127.0.0.1:8001${path}`)).status()) // never 200: refused or missing
    }
  })

  test('UPLOAD: a desktop app sends a picture that appears in the gallery, twice sends it once', async ({ page, request }) => {
    const token = await agentToken(request, 'dev1@test.com')
    const jpeg = await jpegFrom(page)
    const id = '0198a5a0-0000-7000-8000-00000000e2e1'
    const send = () => request.post('/api/v1/agent/screenshots', {
      headers: { Authorization: `Bearer ${token}`, 'X-Agent-Version': '0.1.0', 'X-Device-Id': '11111111-1111-4111-8111-111111111111', Accept: 'application/json' },
      multipart: {
        id,
        takenAt: new Date(Date.now() - 60_000).toISOString(),
        width: '64',
        height: '36',
        image: { name: 'screen.jpg', mimeType: 'image/jpeg', buffer: jpeg },
      },
    })
    const first = await send()
    expect(first.status()).toBe(201)
    const second = await send()
    expect(second.status()).toBe(200)
    expect((await second.json()).status).toBe('duplicate')

    await personPage(page)
    await expect(page.getByRole('list', { name: 'Screenshots of the day' }).locator('img')).toHaveCount(7)
    const size = await (await apiGet(page, '/admin/settings')).json()
    expect(size.screenshotStorageBytes).toBeGreaterThan(0)
  })

  test('UPLOAD: bad pictures and a signed-out app are refused', async ({ page, request }) => {
    const token = await agentToken(request, 'dev1@test.com')
    const headers = { Authorization: `Bearer ${token}`, 'X-Agent-Version': '0.1.0', Accept: 'application/json' }
    const png = await page.evaluate(() => {
      const c = document.createElement('canvas')
      c.width = 4
      c.height = 4
      return c.toDataURL('image/png').split(',')[1]!
    })
    const notJpeg = await request.post('/api/v1/agent/screenshots', { headers, multipart: { id: '0198a5a0-0000-7000-8000-00000000e2e2', takenAt: new Date().toISOString(), width: '4', height: '4', image: { name: 'screen.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(png, 'base64') } } })
    expect(notJpeg.status()).toBe(422)
    const unauthorised = await request.post('/api/v1/agent/screenshots', { headers: { Accept: 'application/json' }, multipart: { id: 'x' } })
    expect(unauthorised.status()).toBe(401)
  })
})

test.describe('who may see the pictures', () => {
  test.describe('a team leader', () => {
    test.use(as('tl1'))

    test('sees their own team member\'s gallery and can open a full picture', async ({ page }) => {
      await personPage(page, 'dev1@test.com')
      await expect(page.getByRole('heading', { name: 'Screenshots' })).toBeVisible()
      await thumbs(page).first().click()
      await expect(page.getByRole('dialog').locator('img')).toBeVisible()
    })

    test('cannot list or open the pictures of someone in another team', async ({ page, browser, allow }) => {
      allow(/\/api\/v1\/(employees|screenshots)\/.* 403/)
      const oic = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: as('oic').storageState })
      const oicPage = await oic.newPage()
      await oicPage.goto('/user')
      const otherId = await idOf(oicPage, 'cs1@test.com')
      const list = await (await oicPage.request.get(`/api/v1/employees/${otherId}/screenshots?day=2026-01-01`, { headers: DASH_HEADERS })).json()
      expect(Array.isArray(list)).toBe(true)
      await oic.close()
      await page.goto('/user')
      expect((await apiGet(page, `/employees/${otherId}/screenshots?day=2026-01-01`)).status()).toBe(403)
    })
  })

  test.describe('the other project manager', () => {
    test.use(as('pm2'))

    test('gets the not-allowed message for Dan Ramos and 403 for his pictures', async ({ page, browser, allow }) => {
      allow(/\/api\/v1\/screenshots\/.* 403/)
      const oic = await browser.newContext({ baseURL: 'http://localhost:3101', storageState: as('oic').storageState })
      const oicPage = await oic.newPage()
      await oicPage.goto('/user')
      const id = await idOf(oicPage, 'dev1@test.com')
      const today = new Date().toISOString().slice(0, 10)
      const list = await (await oicPage.request.get(`/api/v1/employees/${id}/screenshots?day=${today}`, { headers: DASH_HEADERS })).json() as { id: string }[]
      await oic.close()
      await page.goto(`/user/employees/${id}`)
      await expect(page.getByText('You cannot view this person.')).toBeVisible()
      await expect(page.getByRole('heading', { name: 'Screenshots' })).toHaveCount(0)
      expect(list.length).toBeGreaterThan(0)
      expect((await apiGet(page, `/screenshots/${list[0]!.id}/image`)).status()).toBe(403)
      expect((await apiGet(page, `/screenshots/${list[0]!.id}/thumb`)).status()).toBe(403)
    })
  })

  test('a signed-out request for a picture is refused', async ({ request }) => {
    const res = await request.get('/api/v1/screenshots/0198a5a0-0000-7000-8000-000000000000/image', { headers: { Accept: 'application/json' } })
    expect(res.status()).toBe(401)
  })
})

test.describe('the look of the gallery', () => {
  test.use(as('oic'))

  for (const mode of ['light', 'dark'] as const) {
    test(`${mode}: no accessibility problems in the gallery or the viewer`, async ({ page }, testInfo) => {
      await page.emulateMedia({ colorScheme: mode })
      await page.addInitScript(m => localStorage.setItem('theme', m), mode)
      await personPage(page)
      await expect(thumbs(page).first()).toBeVisible()
      await page.waitForLoadState('networkidle')
      const grid = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
      await testInfo.attach(`axe-${mode}-gallery.json`, { body: JSON.stringify(grid.violations, null, 2), contentType: 'application/json' })
      expect(grid.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => v.id)).toEqual([])
      await thumbs(page).first().click()
      await expect(page.locator('[id^="headlessui-dialog-panel"]')).toHaveCSS('opacity', '1')
      await expect.poll(async () => page.getByRole('dialog').locator('img').evaluate(i => (i as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
      const viewer = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
      expect(viewer.violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => v.id)).toEqual([])
      await page.screenshot({ path: `test-results/screenshots-${mode}-viewer.png` })
    })
  }

  test.describe('on a phone', () => {
    test.use({ ...as('oic'), viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true })

    test('the gallery and the viewer fit the screen', async ({ page }) => {
      await personPage(page)
      await expect(thumbs(page).first()).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
      await thumbs(page).first().click()
      const box = await page.getByRole('dialog').locator('img').boundingBox()
      expect(box!.x).toBeGreaterThanOrEqual(0)
      expect(box!.x + box!.width).toBeLessThanOrEqual(376)
    })
  })
})

test.describe.serial('turning them off again', () => {
  test.use(as('oic'))

  test('switching off is saved, uploads are refused with 409, and existing pictures stay visible', async ({ page, request }) => {
    await page.goto('/user/settings')
    await expect(interval(page)).toHaveValue('10')
    await interval(page).selectOption('0')
    await expect(page.getByLabel('At a random moment in each block')).toBeDisabled()
    await save(page).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()

    const token = await agentToken(request, 'dev1@test.com')
    const jpeg = await jpegFrom(page)
    const res = await request.post('/api/v1/agent/screenshots', {
      headers: { Authorization: `Bearer ${token}`, 'X-Agent-Version': '0.1.0', Accept: 'application/json' },
      multipart: { id: '0198a5a0-0000-7000-8000-00000000e2e3', takenAt: new Date().toISOString(), width: '64', height: '36', image: { name: 'screen.jpg', mimeType: 'image/jpeg', buffer: jpeg } },
    })
    expect(res.status()).toBe(409)
    expect((await res.json()).error.code).toBe('SCREENSHOTS_DISABLED')

    await personPage(page)
    await expect(thumbs(page).first()).toBeVisible()
  })
})
