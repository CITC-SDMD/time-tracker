import fs from 'node:fs'
import path from 'node:path'
import { request } from '@playwright/test'
import { reseed } from './helpers/artisan'
import { ACCOUNTS, PASSWORD } from './fixtures'

export const AUTH_DIR = path.resolve(__dirname, '.auth')

export default async function globalSetup() {
  reseed()
  // the mail log is where the tests read welcome and reset links from
  fs.writeFileSync(path.resolve(__dirname, '../api/storage/logs/laravel.log'), '')

  // Sign each demo account in once and keep the session, so specs start signed in without
  // spending the API's login throttle (10 a minute) on every test. Specs that are about signing
  // in use the real form instead.
  fs.mkdirSync(AUTH_DIR, { recursive: true })
  for (const [key, email] of Object.entries(ACCOUNTS)) {
    if (key === 'dev1') continue // individual contributors cannot use the dashboard
    const ctx = await request.newContext({ baseURL: 'http://localhost:3101' })
    await ctx.get('/sanctum/csrf-cookie')
    const cookies = (await ctx.storageState()).cookies
    const xsrf = decodeURIComponent(cookies.find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const res = await ctx.post('/auth/login', {
      data: { email, password: PASSWORD },
      headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
    })
    if (!res.ok()) throw new Error(`could not sign ${email} in: ${res.status()} ${await res.text()}`)
    await ctx.storageState({ path: path.join(AUTH_DIR, `${key}.json`) })
    await ctx.dispose()
  }
}
