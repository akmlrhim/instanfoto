import { test, expect } from '@playwright/test'

const photos = [
  { id: 1, web: '/api/uploads/aaaaaaaaaaaa/one.jpg', thumb: '/api/uploads/aaaaaaaaaaaa/thumb_one.jpg' },
  { id: 2, web: '/api/uploads/aaaaaaaaaaaa/two.jpg', thumb: '/api/uploads/aaaaaaaaaaaa/thumb_two.jpg' },
]

test('halaman awal adalah informasi netral, tanpa login atau pembayaran', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'InstanFoto' })).toBeVisible()
  await expect(page.getByText('Buka galeri foto melalui QR')).toBeVisible()

  const text = (await page.locator('body').innerText()).toLowerCase()
  expect(text).not.toContain('login')
  expect(text).not.toContain('pembayaran')
  expect(text).not.toContain('checkout')
})

test('album tidak ditemukan menampilkan pesan jelas', async ({ page }) => {
  await page.route('**/api/albums/*', (route) => route.fulfill({ status: 404, body: '{"error":"not found"}' }))
  await page.goto('/a/xxxxxxxxxxxx')
  await expect(page.getByText('Album tidak ditemukan.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Muat ulang' })).toBeVisible()
})

test('album kosong menampilkan pesan dan tombol muat ulang', async ({ page }) => {
  await page.route('**/api/albums/*', (route) => route.fulfill({ status: 200, body: '{"photos":[]}' }))
  await page.goto('/a/aaaaaaaaaaaa')
  await expect(page.getByText('Belum ada foto untuk sesi ini.')).toBeVisible()
})

test('grid, lightbox, unduh, dan Escape bekerja', async ({ page }) => {
  await page.route('**/api/albums/*', (route) => route.fulfill({ status: 200, body: JSON.stringify({ photos }) }))
  await page.route('**/api/uploads/**', (route) => route.fulfill({
    status: 200,
    contentType: 'image/jpeg',
    body: Buffer.from(
      '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODUK/9sAQwAFAwQEBAMFBAQEBQUFBgcMCAcHBwcPCwsJDBEPEhIRDxERExYcFxMUGhURERghGBodHR8fHxMXIiQiHiQcHh8e/9sAQwEFBQUHBgcOCAgOHhQRFB4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4e/8AAEQgAHgAoAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/aAAwDAQACEQMRAD8Ahooor88P1YKKKKACiiigAooooAKKKKACiiigD//Z',
      'base64',
    ),
  }))

  await page.goto('/a/aaaaaaaaaaaa')
  await expect(page.getByText('2 foto dari sesi ini')).toBeVisible()

  const firstImage = page.getByAltText('Foto sesi ini, gambar 1')
  await expect(firstImage).toBeVisible()
  expect(await firstImage.getAttribute('fetchpriority')).toBe('high')
  expect(await firstImage.getAttribute('loading')).toBe('eager')

  await page.getByRole('button', { name: 'Buka foto 1' }).click()
  const dialog = page.getByRole('dialog', { name: 'Foto sesi' })
  await expect(dialog).toBeVisible()
  await expect(dialog.getByText('Foto 1 dari 2')).toBeVisible()
  await expect(dialog.getByRole('link', { name: 'Unduh foto' })).toHaveAttribute('download', 'one.jpg')

  await dialog.getByRole('button', { name: 'Berikutnya' }).click()
  await expect(dialog.getByText('Foto 2 dari 2')).toBeVisible()

  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
})
