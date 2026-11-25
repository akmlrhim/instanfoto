import fs from 'node:fs'
import http from 'node:http'
import path from 'node:path'
import { spawn, spawnSync } from 'node:child_process'

import QRCode from 'qrcode'
import sharp from 'sharp'

import {
  TEST_API_KEY,
  base as apiBase,
  check,
  failureCount,
  makeTempDir,
  removeDir,
  root,
  startApi,
  stopApi,
  waitFor as waitForHelper,
} from './helpers.mjs'

const watchDir = makeTempDir()
const dataDir = makeTempDir()
const stateDir = makeTempDir()
const panelBase = 'http://127.0.0.1:4010'
const queueFile = path.join(stateDir, 'queue.json')
const logFile = path.join(stateDir, 'uploader.log')
const qrFile = path.join(stateDir, 'last-qr.json')
const apiEnv = {
  RATE_WRITE_PER_MIN: '10000',
  RATE_WRITE_BURST: '10000',
  RATE_ALBUM_PER_MIN: '10000',
  RATE_ALBUM_BURST: '10000',
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

function waitFor(label, predicate, timeoutMs = 30000) {
  return waitForHelper(predicate, timeoutMs).then((ok) => {
    if (ok === false) {
      console.log(`Timeout while waiting for ${label}`)
    }

    return ok
  })
}

function panelFetch(urlPath, headers = {}, options = {}) {
  return fetch(`${panelBase}${urlPath}`, {
    ...options,
    headers: { 'X-Panel': '1', 'Content-Type': 'application/json', ...headers },
  })
}

function rawGet(headers) {
  return new Promise((resolve, reject) => {
    const request = http.request(
      { host: '127.0.0.1', port: 4010, method: 'GET', path: '/api/status', headers },
      (response) => {
        let body = ''
        response.on('data', (chunk) => {
          body += chunk
        })
        response.on('end', () => resolve(response.statusCode))
      },
    )
    request.on('error', reject)
    request.end()
  })
}

function startUploader(extraEnv = {}) {
  return spawn(process.execPath, ['uploader/index.js'], {
    cwd: root,
    windowsHide: true,
    stdio: 'ignore',
    env: {
      ...process.env,
      WATCH_DIR: watchDir,
      API_BASE_URL: `${apiBase}/api`,
      API_KEY: TEST_API_KEY,
      PANEL_HOST: '127.0.0.1',
      PANEL_PORT: '4010',
      QUEUE_FILE: queueFile,
      LOG_FILE: logFile,
      LAST_QR_FILE: qrFile,
      ...extraEnv,
    },
  })
}

function cleanupFiles() {
  fs.mkdirSync(watchDir, { recursive: true })

  for (const entry of fs.readdirSync(watchDir)) {
    fs.rmSync(path.join(watchDir, entry), { recursive: true, force: true })
  }
}

let api = null
let uploader = null

try {
  cleanupFiles()

  if (!fs.existsSync(path.join(root, 'dist/index.html'))) {
    const build = spawnSync('npm', ['run', 'build'], { cwd: root, windowsHide: true })
    check('Build panel', build.status === 0, 'dist dibuat sebelum uji panel')
  }

  const blocked = startUploader({ PANEL_HOST: '0.0.0.0' })
  const exitCode = await new Promise((resolve) => blocked.on('close', resolve))
  check('SEC-55', exitCode === 1, 'PANEL_HOST=0.0.0.0 ditolak sebelum listen')

  api = startApi(dataDir, apiEnv)
  uploader = startUploader()

  const ready = await waitFor('panel', async () => (await panelFetch('/api/status')).status === 200)
  check('Panel siap', ready, 'panel merespons di 127.0.0.1:4010')

  const noHeader = await fetch(`${panelBase}/api/status`)
  check('SEC-56 header', noHeader.status === 403, 'tanpa X-Panel ditolak 403')

  const wrongOrigin = await fetch(`${panelBase}/api/status`, {
    headers: { 'X-Panel': '1', Origin: 'http://situs-jahat.example' },
  })
  check('SEC-56 origin', wrongOrigin.status === 403, 'origin lain ditolak 403')

  let wrongHost = 0
  try {
    wrongHost = await rawGet({ host: 'situs-jahat.example', 'x-panel': '1' })
  } catch {
    wrongHost = 0
  }
  check('SEC-56 host', wrongHost === 403, `Host asing ditolak ${wrongHost}`)

  const finishWithoutSession = await panelFetch('/api/finish', {}, { method: 'POST' })
  const finishError = await finishWithoutSession.json()
  check(
    'FR-22',
    finishWithoutSession.status === 400 && finishError.error.includes('Mulai sesi'),
    'Selesai tanpa sesi ditolak dengan pesan jelas',
  )

  const panelSource = fs.readFileSync(path.join(root, 'src/views/PanelView.vue'), 'utf8')
  check(
    'FR-20',
    panelSource.includes('Mulai sesi') &&
      panelSource.includes('Selesai dan tampilkan QR') &&
      panelSource.includes('Sesi aktif') &&
      panelSource.includes('Menunggu kirim'),
    'panel Vue memuat dua tombol dan status',
  )

  check(
    'FR-30',
    panelSource.includes('fixed inset-0') && panelSource.includes('w-[min(500px,80vw,52vh)]'),
    'tampilan QR menutup layar penuh',
  )

  check('FR-31', !panelSource.includes('window.print'), 'tidak ada pencetakan di panel')

  const served = await fetch(`${panelBase}/panel`)
  const servedHtml = await served.text()
  check(
    'Tampilan dari src',
    served.status === 200 && servedHtml.includes('<div id="app">'),
    'Express menyajikan build Vue di /panel',
  )

  const started = await panelFetch(
    '/api/start',
    {},
    {
      method: 'POST',
      body: JSON.stringify({ event: 'Uji M3' }),
    },
  )
  const { id } = await started.json()
  check('FR-01 via panel', started.status === 200 && /^[a-z0-9]{12}$/.test(id), `sesi ${id}`)

  const photo = await sharp({
    create: { width: 60, height: 40, channels: 3, background: '#c86432' },
  })
    .jpeg({ quality: 85 })
    .toBuffer()
  fs.writeFileSync(path.join(watchDir, 'm3.jpg'), photo)

  const uploaded = await waitFor('foto masuk', async () => {
    const data = await (await panelFetch('/api/status')).json()
    return data.done >= 1
  })
  check('FR-20 status', uploaded, 'jumlah foto terkirim muncul di status')

  const finished = await panelFetch('/api/finish', {}, { method: 'POST' })
  const result = await finished.json()
  check(
    'FR-21',
    finished.status === 200 && typeof result.qr === 'string' && typeof result.url === 'string',
    'Selesai mengembalikan URL dan QR',
  )

  if (result.url) {
    const expected = await QRCode.toDataURL(result.url, { width: 500, margin: 2 })
    check('FR-30 QR', result.qr === expected, 'QR lebar 500 margin 2 sesuai URL')

    const albumResponse = await fetch(`${apiBase}/api/albums/${id}`)
    const album = await albumResponse.json()
    check(
      'FR-03 alur QR',
      result.url.endsWith(`/a/${id}`) && albumResponse.status === 200 && album.photos?.length === 1,
      'URL QR menunjuk ke /a/{id} dan album berisi 1 foto',
    )
  }

  await sleep(300)
  const statusAfter = await (await panelFetch('/api/status')).json()
  check(
    'FR-31',
    Boolean(statusAfter.lastQr?.qr),
    'QR sesi terakhir tersedia untuk ditampilkan ulang',
  )
} finally {
  if (uploader) {
    uploader.kill()
  }

  await stopApi(api)
  cleanupFiles()
  await removeDir(dataDir)
  await removeDir(watchDir)
  await removeDir(stateDir)
}

console.log(
  failureCount() === 0 ? 'Smoke test M3: PASS' : `Smoke test M3: FAIL (${failureCount()})`,
)
process.exit(failureCount() === 0 ? 0 : 1)
