import { execFileSync } from 'node:child_process'
import path from 'node:path'

const api = path.resolve(__dirname, '../../api')

/** Runs an artisan command against the e2e database (never tracker_dev). */
export function artisan(...args: string[]): string {
  return execFileSync('php', ['artisan', ...args], {
    cwd: api,
    env: { ...process.env, APP_ENV: 'e2e' },
    encoding: 'utf8',
  })
}

/** A clean office: every table rebuilt, then the demo hierarchy (see DemoHierarchySeeder). */
export function reseed(): void {
  artisan('migrate:fresh', '--seed', '--force')
  artisan('db:seed', '--class=DemoHierarchySeeder', '--force')
  artisan('cache:clear')
}
