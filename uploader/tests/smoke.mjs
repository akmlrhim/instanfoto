import fs from 'node:fs'
import path from 'node:path'
import { spawn } from 'node:child_process'

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

function startUploader() {
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
    },
  })
}

async function status() {
  const response = await fetch(`${panelBase}/api/status`, { headers: { 'X-Panel': '1' } })
  return response.json()
}

async function album(id) {
  const response = await fetch(`${apiBase}/api/albums/${id}`)

  return response.json()
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
  const photo = await sharp({
    create: { width: 60, height: 40, channels: 3, background: '#c86432' },
  })
    .jpeg({ quality: 85 })
    .toBuffer()

  api = startApi(dataDir, apiEnv)
  uploader = startUploader()

  const ready = await waitFor(
    'API',
    async () => (await fetch(`${apiBase}/api/albums/xxxxxxxxxxxx`)).status === 404,
  )
  check('API siap', ready, 'server PHP merespons')

  const panelReady = await waitFor('panel', async () => (await status()).sessionId === null)
  check('Panel siap', panelReady, 'uploader merespons di port 4010')

  fs.writeFileSync(path.join(watchDir, 'ignored.jpg'), photo)
  await sleep(3500)

  const queueBeforeSession = fs.existsSync(queueFile)
    ? JSON.parse(fs.readFileSync(queueFile, 'utf8'))
    : { items: [] }
  const ignored =
    queueBeforeSession.items.length === 0 &&
    fs.existsSync(logFile) &&
    fs.readFileSync(logFile, 'utf8').includes('\tignored\t')
  check('FR-13', ignored, 'foto tanpa sesi aktif diabaikan dan dicatat')

  const started = await fetch(`${panelBase}/api/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Panel': '1' },
    body: JSON.stringify({ event: 'Uji M2' }),
  })
  const { id } = await started.json()
  check('FR-01 via panel', started.status === 200 && /^[a-z0-9]{12}$/.test(id), `sesi ${id}`)

  fs.writeFileSync(path.join(watchDir, 'first.jpg'), photo)
  const firstUploaded = await waitFor('foto pertama', async () => (await status()).done >= 1)
  check('FR-10, FR-11, FR-02', firstUploaded, 'foto pertama terunggah')

  const firstAlbum = await album(id)
  check(
    'FR-04 awal',
    firstAlbum.photos?.length === 1,
    `album berisi ${firstAlbum.photos?.length} foto`,
  )

  await stopApi(api)
  await sleep(1500)

  fs.writeFileSync(path.join(watchDir, 'second.jpg'), photo)
  await sleep(7000)

  const duringOutage = await status()
  check(
    'FR-12 saat putus',
    duringOutage.pending >= 1 && duringOutage.done === 1,
    `pending ${duringOutage.pending}, done ${duringOutage.done}`,
  )

  api = startApi(dataDir, apiEnv)
  const apiBack = await waitFor(
    'API pulih',
    async () => (await fetch(`${apiBase}/api/albums/${id}`)).status === 200,
  )
  check('API pulih', apiBack, 'server PHP kembali')

  const secondUploaded = await waitFor('foto kedua', async () => (await status()).done >= 2, 45000)
  check('FR-12 retry', secondUploaded, 'antrean lanjut setelah koneksi kembali')

  const afterRecovery = await album(id)
  check(
    'E2E-2 tanpa ganda',
    afterRecovery.photos?.length === 2,
    `album berisi ${afterRecovery.photos?.length} foto`,
  )

  uploader.kill()
  await sleep(1500)
  uploader = startUploader()
  const restarted = await waitFor('uploader restart', async () => (await status()).done >= 2)
  check('FR-14 restart', restarted, 'antrean tersisa dilanjutkan setelah restart')

  await sleep(2000)
  const afterRestart = await album(id)
  check(
    'FR-14 tanpa duplikat',
    afterRestart.photos?.length === 2,
    `album masih ${afterRestart.photos?.length} foto`,
  )

  const queueText = fs.readFileSync(queueFile, 'utf8')
  const logText = fs.readFileSync(logFile, 'utf8')
  check('SEC-58 queue', !queueText.includes(TEST_API_KEY), 'queue.json tidak memuat API key')
  check('SEC-58 log', !logText.includes(TEST_API_KEY), 'log tidak memuat API key')

  const queue = JSON.parse(queueText)
  const allowedKeys = new Set(['path', 'sessionId', 'attempts', 'status'])
  const cleanItems = queue.items.every((item) =>
    Object.keys(item).every((name) => allowedKeys.has(name)),
  )
  check('SEC-58 isi queue', cleanItems, 'item queue hanya berisi path, sessionId, attempts, status')
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
  failureCount() === 0 ? 'Smoke test M2: PASS' : `Smoke test M2: FAIL (${failureCount()})`,
)
process.exit(failureCount() === 0 ? 0 : 1)
