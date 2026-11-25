import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
export const TEST_API_KEY = 'c'.repeat(64)
export const TEST_PORT = Number(process.env.TEST_PORT ?? 8123)
export const base = `http://127.0.0.1:${TEST_PORT}`

const phpBinary = process.env.PHP_BINARY ?? 'php'

export function makeTempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'instanfoto-test-'))
}

export async function removeDir(directory) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    try {
      fs.rmSync(directory, { recursive: true, force: true })
      return
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 250))
    }
  }
}

let failures = 0

export function check(id, ok, detail) {
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${id}: ${detail}`)

  if (ok === false) {
    failures += 1
  }
}

export function failureCount() {
  return failures
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export function startApi(dataDir, extraEnv = {}) {
  const logFile = path.join(dataDir, 'server.log')
  const child = spawn(
    phpBinary,
    [
      '-d',
      'upload_max_filesize=8M',
      '-d',
      'post_max_size=10M',
      '-d',
      `error_log=${logFile}`,
      '-S',
      `127.0.0.1:${TEST_PORT}`,
      '-t',
      path.join(root, 'api/public'),
      path.join(root, 'api/public/router.php'),
    ],
    {
      cwd: root,
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: {
        ...process.env,
        API_KEY: TEST_API_KEY,
        BASE_URL: base,
        DATA_DIR: dataDir,
        ...extraEnv,
      },
    },
  )

  let output = ''
  child.stdout.on('data', (chunk) => {
    output += chunk
  })
  child.stderr.on('data', (chunk) => {
    output += chunk
  })

  return {
    child,
    output: () => {
      let log = ''

      try {
        log = fs.readFileSync(logFile, 'utf8')
      } catch {
        // Log belum dibuat.
      }

      return output + log
    },
  }
}

export async function waitFor(predicate, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    try {
      if (await predicate()) {
        return true
      }
    } catch {
      // Not ready yet.
    }

    await sleep(200)
  }

  return false
}

export async function waitForHealth() {
  return waitFor(async () => (await fetch(`${base}/api/health`)).status === 200)
}

export async function stopApi(api) {
  if (!api || api.child.exitCode !== null) {
    return
  }

  await new Promise((resolve) => {
    const timer = setTimeout(() => {
      api.child.kill('SIGKILL')
      resolve()
    }, 3000)

    api.child.once('exit', () => {
      clearTimeout(timer)
      resolve()
    })

    api.child.kill()
  })
}

export async function waitForExit(child, timeoutMs = 15000) {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, timeoutMs)
    child.once('exit', () => {
      clearTimeout(timer)
      resolve()
    })
  })
}
