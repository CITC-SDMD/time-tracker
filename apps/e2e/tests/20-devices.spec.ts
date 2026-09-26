import { as, expect, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

test.beforeEach(() => artisan('cache:clear'))

// The PCs someone is signed in to the desktop app on (docs/SECURITY_REVIEW.md). The app is not part of this run, so the
// sign-in is written to the database the way a login stores it: a token named agent-<device id> and a device row.
const signInPc = (email: string, deviceId: string, computer: string) => artisan(
  'tinker',
  `--execute=use App\\Models\\User; use App\\Models\\Device; $u = User::where('email', '${email}')->first(); $u->tokens()->where('name', 'like', 'agent-%')->delete(); $u->createToken('agent-${deviceId}', ['agent'], now()->addDays(7)); Device::unguarded(fn () => Device::updateOrCreate(['id' => '${deviceId}'], ['organization_id' => $u->organization_id, 'user_id' => $u->id, 'computer_name' => '${computer}', 'agent_version' => '0.2.0', 'first_seen_at' => now(), 'last_seen_at' => now()]));`,
)

const LAPTOP = '9d1c0b7e-0000-4000-8000-00000000d001'

test.describe('signed-in PCs', () => {
  test.use(as('oic'))

  test('the admin signs a person\'s PC out, and cancelling leaves it', async ({ page }) => {
    signInPc('dev1@test.com', LAPTOP, 'LAPTOP-DAN')

    await page.goto('/user/people')
    await page.getByRole('link', { name: 'Dan Ramos' }).click()
    await expect(page.getByRole('heading', { name: 'Desktop app sign-ins' })).toBeVisible()
    await expect(page.getByRole('listitem').filter({ hasText: 'LAPTOP-DAN' })).toBeVisible()

    await page.getByRole('listitem').getByRole('button', { name: 'Sign out' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()
    await expect(page.getByRole('listitem').filter({ hasText: 'LAPTOP-DAN' })).toBeVisible()

    await page.getByRole('listitem').getByRole('button', { name: 'Sign out' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Sign out' }).click()
    await expect(page.getByText('No PC is signed in')).toBeVisible()
  })

  test('a person sees their own PCs on the profile page', async ({ page }) => {
    signInPc('oic@test.com', LAPTOP, 'LAPTOP-OIC')

    await page.goto('/user/profile')
    await expect(page.getByRole('heading', { name: 'Desktop app sign-ins' })).toBeVisible()
    await expect(page.getByRole('listitem').filter({ hasText: 'LAPTOP-OIC' })).toBeVisible()
    await page.getByRole('listitem').getByRole('button', { name: 'Sign out' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Sign out' }).click()
    await expect(page.getByText('No PC is signed in')).toBeVisible()
  })
})
