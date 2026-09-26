import { createHmac } from 'node:crypto'

// The 6-digit code an authenticator app shows (RFC 6238: HMAC-SHA1, 30-second steps), so a test can play the app.
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function base32Decode(text: string): Buffer {
  let bits = ''
  for (const char of text.replace(/=+$/, '').toUpperCase())
    bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0')
  const bytes: number[] = []
  for (let i = 0; i + 8 <= bits.length; i += 8)
    bytes.push(Number.parseInt(bits.slice(i, i + 8), 2))
  return Buffer.from(bytes)
}

/** The code for the current step, or `steps` 30-second steps later (the server accepts one step either way). */
export function totp(secret: string, steps = 0): string {
  const counter = BigInt(Math.floor(Date.now() / 30_000) + steps)
  const message = Buffer.alloc(8)
  message.writeBigUInt64BE(counter)
  const digest = createHmac('sha1', base32Decode(secret)).update(message).digest()
  const offset = digest[digest.length - 1]! & 0x0F
  const value = ((digest[offset]! & 0x7F) << 24) | (digest[offset + 1]! << 16) | (digest[offset + 2]! << 8) | digest[offset + 3]!
  return String(value % 1_000_000).padStart(6, '0')
}
