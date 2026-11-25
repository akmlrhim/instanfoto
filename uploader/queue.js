import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const defaultQueuePath = path.join(path.dirname(fileURLToPath(import.meta.url)), 'queue.json')

const statuses = new Set(['pending', 'done', 'skipped'])

function queuePath() {
  return process.env.QUEUE_FILE ? path.resolve(process.env.QUEUE_FILE) : defaultQueuePath
}

function normalize(item) {
  if (
    !item ||
    typeof item.path !== 'string' ||
    typeof item.sessionId !== 'string' ||
    !statuses.has(item.status)
  ) {
    return null
  }

  return {
    path: item.path,
    sessionId: item.sessionId,
    attempts: Number.isInteger(item.attempts) && item.attempts >= 0 ? item.attempts : 0,
    status: item.status,
  }
}

export function loadQueue() {
  if (!fs.existsSync(queuePath())) {
    return { sessionId: null, items: [] }
  }

  try {
    const data = JSON.parse(fs.readFileSync(queuePath(), 'utf8'))
    const items = Array.isArray(data.items) ? data.items.map(normalize).filter(Boolean) : []

    return {
      sessionId: typeof data.sessionId === 'string' ? data.sessionId : null,
      items,
    }
  } catch {
    return { sessionId: null, items: [] }
  }
}

// Atomic write so a crash cannot leave a partly written queue (FR-14).
export function saveQueue(state) {
  const temporary = `${queuePath()}.tmp`
  fs.writeFileSync(temporary, JSON.stringify(state, null, 2))
  fs.renameSync(temporary, queuePath())
}
