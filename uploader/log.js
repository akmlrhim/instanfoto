import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const defaultLogPath = path.join(path.dirname(fileURLToPath(import.meta.url)), 'uploader.log')

function logPath() {
  return process.env.LOG_FILE ? path.resolve(process.env.LOG_FILE) : defaultLogPath
}

// SEC-58: only a path, a session id, and a status are written. Never an API key or photo content.
export function log(action, file, sessionId = '-', detail = '') {
  const line = [new Date().toISOString(), action, file ?? '-', sessionId ?? '-', detail]
    .filter(Boolean)
    .join('\t')

  fs.appendFileSync(logPath(), `${line}\n`)
  console.log(line)
}
