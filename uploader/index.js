import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import axios from 'axios'
import chokidar from 'chokidar'
import express from 'express'
import FormData from 'form-data'
import QRCode from 'qrcode'
import sharp from 'sharp'

import { log } from './log.js'
import { loadQueue, saveQueue } from './queue.js'

const here = path.dirname(fileURLToPath(import.meta.url))

function loadEnv() {
  const file = path.join(here, '.env')

  if (!fs.existsSync(file)) {
    return
  }

  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/)

    if (!match) {
      continue
    }

    if (!(match[1] in process.env)) {
      process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2')
    }
  }
}

loadEnv()

const {
  WATCH_DIR,
  API_BASE_URL,
  API_KEY,
  PANEL_HOST = '127.0.0.1',
  PANEL_PORT = '4000',
  PHOTO_MAX_EDGE = '2000',
  JPEG_QUALITY = '85',
  RETRY_INTERVAL_MS = '5000',
  MAX_RETRIES = '20',
} = process.env

if (!WATCH_DIR || !API_BASE_URL || !API_KEY) {
  console.error('WATCH_DIR, API_BASE_URL, dan API_KEY wajib diisi di uploader/.env')
  process.exit(1)
}

// SEC-41: TLS verification stays on. Node already verifies by default.
if (process.env.NODE_TLS_REJECT_UNAUTHORIZED === '0') {
  console.error('Verifikasi TLS tidak boleh dimatikan')
  process.exit(1)
}

// SEC-55: the panel must never listen on a LAN or public interface.
if (!['127.0.0.1', 'localhost'].includes(PANEL_HOST)) {
  console.error('PANEL_HOST harus 127.0.0.1 atau localhost')
  process.exit(1)
}

const apiBase = API_BASE_URL.replace(/\/+$/, '')
const state = loadQueue()
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const relative = (file) => path.relative(WATCH_DIR, file)
const lastQrFile = process.env.LAST_QR_FILE
  ? path.resolve(process.env.LAST_QR_FILE)
  : path.join(here, 'last-qr.json')

function loadLastQr() {
  if (!fs.existsSync(lastQrFile)) {
    return null
  }

  try {
    const data = JSON.parse(fs.readFileSync(lastQrFile, 'utf8'))
    return typeof data.qr === 'string' && typeof data.url === 'string' ? data : null
  } catch {
    return null
  }
}

let lastQr = loadLastQr()

function saveLastQr(value) {
  lastQr = value

  if (value === null) {
    fs.rmSync(lastQrFile, { force: true })
    return
  }

  fs.writeFileSync(lastQrFile, JSON.stringify(value))
}

fs.mkdirSync(WATCH_DIR, { recursive: true })

const watcher = chokidar.watch(WATCH_DIR, {
  ignoreInitial: true,
  persistent: true,
  awaitWriteFinish: {
    stabilityThreshold: 1500,
    pollInterval: 100,
  },
})

watcher.on('add', (file) => {
  if (!/\.(jpe?g)$/i.test(file)) {
    return
  }

  if (state.items.some((item) => item.path === file)) {
    log('duplicate', relative(file), state.sessionId ?? '-')
    return
  }

  if (!state.sessionId) {
    log('ignored', relative(file), '-')
    return
  }

  state.items.push({
    path: file,
    sessionId: state.sessionId,
    attempts: 0,
    status: 'pending',
  })
  saveQueue(state)
  log('queued', relative(file), state.sessionId)
})

async function uploadPhoto(item) {
  const image = await sharp(item.path)
    .rotate()
    .resize({
      width: Number(PHOTO_MAX_EDGE),
      height: Number(PHOTO_MAX_EDGE),
      fit: 'inside',
      withoutEnlargement: true,
    })
    .jpeg({ quality: Number(JPEG_QUALITY) })
    .toBuffer()

  const form = new FormData()
  form.append('photo', image, { filename: 'photo.jpg', contentType: 'image/jpeg' })

  const response = await axios.post(`${apiBase}/sessions/${item.sessionId}/photos`, form, {
    headers: { ...form.getHeaders(), 'X-Api-Key': API_KEY },
    timeout: 20000,
  })

  if (response.status !== 201) {
    throw new Error(`unexpected status ${response.status}`)
  }
}

async function worker() {
  for (;;) {
    const item = state.items.find((entry) => entry.status === 'pending')

    if (!item) {
      await sleep(500)
      continue
    }

    const file = relative(item.path)

    if (item.attempts >= Number(MAX_RETRIES)) {
      item.status = 'skipped'
      saveQueue(state)
      log('skipped', file, item.sessionId)
      continue
    }

    try {
      await uploadPhoto(item)
      item.status = 'done'
      saveQueue(state)
      log('uploaded', file, item.sessionId)
    } catch (error) {
      item.attempts += 1
      saveQueue(state)
      const detail =
        typeof error?.response?.status === 'number' ? `http ${error.response.status}` : 'network'
      log('failed', file, item.sessionId, `${detail} attempt ${item.attempts}`)
      await sleep(Number(RETRY_INTERVAL_MS))
    }
  }
}

function apiRequest(method, urlPath, data) {
  return axios({
    method,
    url: `${apiBase}${urlPath}`,
    data,
    headers: { 'X-Api-Key': API_KEY, 'Content-Type': 'application/json' },
    timeout: 20000,
  })
}

async function waitForEmptyQueue(timeoutMs = 120000) {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    if (!state.items.some((item) => item.status === 'pending')) {
      return true
    }
    await sleep(500)
  }

  return false
}

// SEC-56: block cross-origin calls and DNS rebinding against the local panel.
const allowedHosts = new Set([`localhost:${PANEL_PORT}`, `127.0.0.1:${PANEL_PORT}`])

function panelGuard(request, response, next) {
  const host = request.headers.host ?? ''

  if (!allowedHosts.has(host)) {
    response.status(403).json({ error: 'Permintaan ditolak' })
    return
  }

  const origin = request.headers.origin

  if (origin && origin !== `http://${host}`) {
    response.status(403).json({ error: 'Permintaan ditolak' })
    return
  }

  if (request.headers['x-panel'] !== '1') {
    response.status(403).json({ error: 'Permintaan ditolak' })
    return
  }

  next()
}

const app = express()
app.use(express.json())
app.use(['/api/start', '/api/finish', '/api/status'], panelGuard)

app.post('/api/start', async (request, response) => {
  const event = typeof request.body?.event === 'string' ? request.body.event : ''

  try {
    const result = await apiRequest('post', '/sessions', { event })
    state.sessionId = result.data.id
    saveLastQr(null)
    saveQueue(state)
    log('started', '-', state.sessionId)
    response.status(200).json({ id: state.sessionId })
  } catch {
    response.status(502).json({ error: 'API tidak tersedia' })
  }
})

app.post('/api/finish', async (_request, response) => {
  const id = state.sessionId

  // FR-22: finish without an active session is rejected with a clear message.
  if (!id) {
    response.status(400).json({ error: 'Tidak ada sesi aktif. Mulai sesi terlebih dahulu.' })
    return
  }

  // FR-21: give the camera a moment, then wait until the queue is empty.
  await sleep(3000)

  if (!(await waitForEmptyQueue())) {
    response.status(503).json({ error: 'Masih ada foto yang menunggu kirim' })
    return
  }

  try {
    const result = await apiRequest('post', `/sessions/${id}/finish`)
    const url = result.data.url
    const qr = await QRCode.toDataURL(url, { width: 500, margin: 2 })

    saveLastQr({ id, url, qr })
    state.sessionId = null
    saveQueue(state)
    log('finished', '-', id)
    response.status(200).json({ url, qr })
  } catch {
    response.status(502).json({ error: 'API tidak tersedia' })
  }
})

app.get('/api/status', (_request, response) => {
  response.json({
    sessionId: state.sessionId,
    pending: state.items.filter((item) => item.status === 'pending').length,
    done: state.items.filter((item) => item.status === 'done').length,
    skipped: state.items.filter((item) => item.status === 'skipped').length,
    lastQr,
  })
})

// The panel view lives in src/views/PanelView.vue and is served from the Vite build.
const distDir = path.resolve(here, '..', 'dist')
app.use(express.static(distDir))
app.get('/{*splat}', (_request, response) => {
  response.sendFile(path.join(distDir, 'index.html'))
})

app.listen(Number(PANEL_PORT), PANEL_HOST, () => {
  console.log(`Panel uploader di http://${PANEL_HOST}:${PANEL_PORT}/panel`)
  if (!fs.existsSync(path.join(distDir, 'index.html'))) {
    console.log('Jalankan npm run build dulu agar tampilan panel tersedia.')
  }
})

worker()
