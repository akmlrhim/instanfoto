import fs from 'node:fs'
import path from 'node:path'
import http from 'node:http'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const folder = path.join(root, '.demo/foto-hp')
const port = Number(process.env.PHONE_DROP_PORT ?? 4001)
const maxBytes = 12 * 1024 * 1024

fs.mkdirSync(folder, { recursive: true })

const page = `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kirim foto dari HP</title>
<style>
  body { margin: 0; padding: 24px; background: #ffffff; color: #0a1317;
         font-family: system-ui, -apple-system, Arial, sans-serif; }
  h1 { font-size: 24px; font-weight: 500; margin: 0 0 8px; }
  p { color: #444950; font-size: 14px; line-height: 1.5; }
  input[type=file] { position: absolute; width: 1px; height: 1px; opacity: 0; }
  button, label.action { display: block; width: 100%; min-height: 48px; margin-top: 16px;
                  font: inherit; font-size: 16px; border-radius: 100px; text-align: center;
                  box-sizing: border-box; padding: 12px 16px; }
  label.action { border: 2px solid #0a1317; color: #0a1317; font-weight: 700; cursor: pointer; }
  button { border: 0; background: #000000; color: #ffffff; font-weight: 700; cursor: pointer; }
  #pesan { margin-top: 16px; font-weight: 700; min-height: 24px; }
  label.opsi { display: flex; align-items: center; gap: 8px; margin-top: 16px;
               font-size: 14px; color: #444950; }
  label.opsi input { width: 20px; height: 20px; margin: 0; opacity: 1; position: static; }
</style>
</head>
<body>
  <h1>InstanFoto, demo foto</h1>
  <p>Setelah foto diambil, foto langsung dikirim ke laptop. Tidak perlu menekan tombol kirim.</p>

  <input type="file" id="foto" accept="image/jpeg,image/heic,image/heif" capture="environment">
  <label class="action" for="foto">Buka kamera dan ambil foto</label>

  <label class="opsi">
    <input type="checkbox" id="lanjut" checked>
    Setelah terkirim, buka kamera lagi otomatis
  </label>

  <p id="pesan" role="status" aria-live="polite">Siap. Buka kamera untuk mengambil foto pertama.</p>

<script>
  const input = document.querySelector('#foto')
  const pesan = document.querySelector('#pesan')
  const lanjut = document.querySelector('#lanjut')
  let mengirim = false

  async function kirim(file) {
    if (mengirim) return
    mengirim = true
    pesan.textContent = 'Mengirim foto...'

    try {
      const response = await fetch('/upload', {
        method: 'POST',
        headers: { 'Content-Type': file.type || 'image/jpeg' },
        body: file,
      })
      const data = await response.json()

      if (!response.ok) {
        pesan.textContent = data.error || 'Gagal mengirim foto.'
        return
      }

      pesan.textContent = 'Foto masuk ke server: ' + data.file

      if (lanjut.checked) {
        window.setTimeout(() => input.click(), 700)
      }
    } catch {
      pesan.textContent = 'Gagal terhubung ke laptop.'
    } finally {
      mengirim = false
    }
  }

  input.addEventListener('change', () => {
    const file = input.files && input.files[0]
    if (file) {
      kirim(file)
    }
  })

  window.setTimeout(() => input.click(), 400)
</script>
</body>
</html>`

async function toJpeg(bytes) {
  const jpegMagic = bytes.length > 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff

  if (jpegMagic) {
    return bytes
  }

  const converted = await sharp(bytes).rotate().jpeg({ quality: 85 }).toBuffer()
  return converted
}

const server = http.createServer((request, response) => {
  if (request.method === 'GET' && (request.url === '/' || request.url === '/phone')) {
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
    response.end(page)
    return
  }

  if (request.method === 'POST' && request.url === '/upload') {
    const chunks = []
    let size = 0
    let tooLarge = false

    request.on('data', (chunk) => {
      size += chunk.length

      if (size > maxBytes) {
        tooLarge = true
        request.destroy()
        return
      }

      chunks.push(chunk)
    })

    request.on('end', async () => {
      if (tooLarge) {
        return
      }

      try {
        const original = Buffer.concat(chunks)
        const jpeg = await toJpeg(original)
        const name = `hp-${Date.now()}.jpg`

        fs.writeFileSync(path.join(folder, name), jpeg)

        response.writeHead(200, { 'Content-Type': 'application/json' })
        response.end(JSON.stringify({ ok: true, file: name }))
      } catch {
        response.writeHead(415, { 'Content-Type': 'application/json' })
        response.end(JSON.stringify({ error: 'Foto tidak dikenali. Pakai JPEG dari kamera HP.' }))
      }
    })

    return
  }

  response.writeHead(404)
  response.end()
})

server.listen(port, '0.0.0.0', () => {
  console.log(`Kirim foto dari HP: http://<IP-LAPTOP>:${port}/phone`)
  console.log(`Folder tujuan: ${folder}`)
})
