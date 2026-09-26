import fs from 'node:fs'
import path from 'node:path'

const logFile = path.resolve(__dirname, '../../api/storage/logs/laravel.log')

/** Every set-password / reset link the API "sent" (MAIL_MAILER=log writes the message to the log). */
export function mailLinks(): string[] {
  let text = ''
  try {
    text = fs.readFileSync(logFile, 'utf8')
  }
  catch {
    return []
  }
  // the message body keeps the link as plain text, sometimes wrapped as <http://...>
  const all = [...text.matchAll(/https?:\/\/localhost:3101\/[^\s<>"')\]]+/g)]
    .map(m => m[0].replace(/&amp;/g, '&'))
    .filter(u => u.includes('link='))
  return [...new Set(all)]
}

/** The newest link, waiting up to `ms` for the mail to land. */
export async function latestMailLink(ms = 10_000): Promise<string> {
  const end = Date.now() + ms
  while (Date.now() < end) {
    const links = mailLinks()
    if (links.length) return links[links.length - 1]!
    await new Promise(r => setTimeout(r, 250))
  }
  throw new Error('no mail link appeared in the log')
}

export function clearMailLog(): void {
  fs.writeFileSync(logFile, '')
}

/** The number of links so far, so a test can wait for a NEW one. */
export function mailLinkCount(): number {
  return mailLinks().length
}

export async function nextMailLink(before: number, ms = 10_000): Promise<string> {
  const end = Date.now() + ms
  while (Date.now() < end) {
    const links = mailLinks()
    if (links.length > before) return links[links.length - 1]!
    await new Promise(r => setTimeout(r, 250))
  }
  throw new Error('no new mail link appeared in the log')
}

/** Everything the API "sent" so far, as text (MAIL_MAILER=log writes each message to the log). */
export function mailLogText(): string {
  try {
    return fs.readFileSync(logFile, 'utf8')
  }
  catch {
    return ''
  }
}

/** Waits up to `ms` for a mail whose text contains every one of `parts`, and returns the log text. */
export async function waitForMail(parts: string[], ms = 10_000): Promise<string> {
  const end = Date.now() + ms
  while (Date.now() < end) {
    const text = mailLogText()
    if (parts.every(part => text.includes(part))) return text
    await new Promise(r => setTimeout(r, 250))
  }
  throw new Error(`no mail with ${parts.join(' and ')} appeared in the log`)
}
