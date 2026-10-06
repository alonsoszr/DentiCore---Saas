import { createHmac } from 'node:crypto'

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function base32Decode(secret: string): Buffer {
  const clean = secret.replace(/[\s=]/g, '').toUpperCase()
  let bits = ''
  for (const char of clean) {
    bits += BASE32.indexOf(char).toString(2).padStart(5, '0')
  }
  const bytes = bits.match(/.{8}/g) ?? []
  return Buffer.from(bytes.map((byte) => parseInt(byte, 2)))
}

/** Código TOTP de 6 dígitos (RFC 6238, SHA-1, 30 s), como la app de autenticación. */
export function totp(secret: string, now = Date.now()): string {
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)))
  const hmac = createHmac('sha1', base32Decode(secret)).update(counter).digest()
  const offset = hmac[hmac.length - 1] & 0x0f
  const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000
  return String(value).padStart(6, '0')
}

/** RUC sintético de persona jurídica (20…) con dígito verificador válido (SUNAT, módulo 11). */
export function syntheticRuc(): string {
  const body = `20${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`
  const weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]
  const sum = [...body].reduce((total, digit, index) => total + Number(digit) * weights[index], 0)
  const check = (11 - (sum % 11)) % 10
  return `${body}${check}`
}

/** Secreto TOTP sintético del Súper Administrador de la semilla local (DatabaseSeeder). */
export const SUPER_ADMIN = {
  email: 'admin@denticore.test',
  password: 'password',
  totpSecret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
}

export const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8025'
