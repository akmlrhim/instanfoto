<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import BrandHeader from '@/components/BrandHeader.vue'
import { lockBodyScroll, unlockBodyScroll } from '@/lib/scrollLock'

const sessionId = ref('')
const pending = ref(0)
const done = ref(0)
const message = ref('')
const isError = ref(false)
const busy = ref(false)
const lastQr = ref(null)
const qrVisible = ref(false)
const qrDialog = ref(null)
const qrClose = ref(null)

const hasSession = computed(() => Boolean(sessionId.value))

let qrPreviousFocus = null
let qrScrollLocked = false

async function api(urlPath, options = {}) {
  const response = await fetch(urlPath, {
    ...options,
    headers: {
      'X-Panel': '1',
      'Content-Type': 'application/json',
      ...(options.headers ?? {}),
    },
  })
  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new Error(data.error ?? 'Permintaan gagal')
  }

  return data
}

function setMessage(text, error = false) {
  message.value = text
  isError.value = error
}

async function refresh() {
  try {
    const data = await api('/api/status')
    sessionId.value = data.sessionId ?? ''
    pending.value = data.pending ?? 0
    done.value = data.done ?? 0
    lastQr.value = data.lastQr ?? null
  } catch (error) {
    setMessage(error.message, true)
  }
}

async function start() {
  busy.value = true
  setMessage('Memulai sesi...')

  try {
    const data = await api('/api/start', {
      method: 'POST',
      body: JSON.stringify({ event: '' }),
    })
    setMessage(`Sesi ${data.id} dimulai.`)
    await refresh()
  } catch (error) {
    setMessage(error.message, true)
  } finally {
    busy.value = false
    await refresh()
  }
}

async function finish() {
  busy.value = true
  setMessage('Menunggu semua foto terkirim...')

  try {
    const data = await api('/api/finish', { method: 'POST' })
    lastQr.value = data
    qrVisible.value = true
    await refresh()
  } catch (error) {
    setMessage(error.message, true)
  } finally {
    busy.value = false
    await refresh()
  }
}

function showQr() {
  if (lastQr.value) {
    qrVisible.value = true
  }
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    qrVisible.value = false
    return
  }

  if (!qrVisible.value || event.key !== 'Tab' || !qrDialog.value) {
    return
  }

  const items = Array.from(qrDialog.value.querySelectorAll('button:not([disabled])'))

  if (items.length === 0) {
    return
  }

  const first = items[0]
  const last = items[items.length - 1]

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(qrVisible, async (visible) => {
  if (visible) {
    qrPreviousFocus = document.activeElement
    qrScrollLocked = true
    lockBodyScroll()
    await nextTick()
    qrClose.value?.focus()
    return
  }

  if (qrScrollLocked) {
    qrScrollLocked = false
    unlockBodyScroll()
  }

  if (qrPreviousFocus instanceof HTMLElement) {
    qrPreviousFocus.focus()
    qrPreviousFocus = null
  }
})

let refreshTimer = null

onMounted(() => {
  refresh()
  window.addEventListener('keydown', onKeydown)
  refreshTimer = window.setInterval(refresh, 2000)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (refreshTimer !== null) {
    window.clearInterval(refreshTimer)
  }
  if (qrScrollLocked) {
    qrScrollLocked = false
    unlockBodyScroll()
  }
})
</script>

<template>
  <div class="flex min-h-screen min-h-dvh flex-col bg-canvas">
    <BrandHeader label="Panel fotografer" />

    <main class="album-shell flex-1 pb-16 pt-6 sm:pt-10">
      <div class="grid w-full gap-6 lg:grid-cols-[1.15fr_1fr] lg:items-start">
        <section
          class="overflow-hidden rounded-card border border-hairline bg-surface"
          aria-labelledby="status-title"
        >
          <h2 id="status-title" class="sr-only">Status sesi</h2>

          <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-6 sm:px-8">
            <div class="min-w-0">
              <p class="text-sm text-muted">Sesi aktif</p>
              <p class="mt-1 break-all text-lg font-medium text-ink">
                {{ sessionId || 'Belum ada sesi' }}
              </p>
            </div>
            <span
              class="inline-flex shrink-0 items-center gap-2 rounded-pill border border-hairline bg-canvas px-3 py-1.5 text-sm font-medium"
            >
              <span
                class="h-2 w-2 rounded-full"
                :class="hasSession ? 'bg-success' : 'bg-disabled-text'"
                aria-hidden="true"
              />
              {{ hasSession ? 'Berjalan' : 'Belum dimulai' }}
            </span>
          </div>

          <div class="grid grid-cols-2 border-t border-hairline bg-canvas">
            <div class="px-6 py-5 sm:px-8">
              <p class="text-sm text-muted">Menunggu kirim</p>
              <p class="mt-1 text-3xl font-medium tabular-nums text-ink">{{ pending }}</p>
              <p class="text-sm text-muted">foto</p>
            </div>
            <div class="border-l border-hairline px-6 py-5 sm:px-8">
              <p class="text-sm text-muted">Terkirim</p>
              <p class="mt-1 text-3xl font-medium tabular-nums text-ink">{{ done }}</p>
              <p class="text-sm text-muted">foto</p>
            </div>
          </div>
        </section>

        <div class="flex flex-col">
          <p
            class="min-h-6 text-sm"
            :class="isError ? 'text-critical' : 'text-muted'"
            role="status"
            aria-live="polite"
          >
            {{ message }}
          </p>

          <div class="mt-4 flex flex-col gap-3">
            <button
              type="button"
              :class="hasSession ? 'quiet-button w-full' : 'primary-button w-full'"
              :disabled="busy"
              @click="start"
            >
              Mulai sesi
            </button>
            <button
              type="button"
              class="primary-button w-full"
              :disabled="busy || !hasSession"
              @click="finish"
            >
              Selesai dan tampilkan QR
            </button>
            <button v-if="lastQr" type="button" class="quiet-button w-full" @click="showQr">
              Tampilkan kembali QR
            </button>
          </div>

          <p class="mt-4 text-sm text-muted">QR muncul setelah semua foto terkirim.</p>
        </div>
      </div>
    </main>

    <div
      v-if="qrVisible && lastQr"
      ref="qrDialog"
      class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-surface p-6"
      role="dialog"
      aria-modal="true"
      aria-label="Tampilan QR untuk tamu"
    >
      <div class="flex w-full max-w-xl flex-col items-center gap-4 text-center">
        <p class="text-sm font-medium text-muted">InstanFoto</p>
        <h2 class="text-2xl font-medium tracking-tight text-balance sm:text-3xl">
          Pindai untuk membuka foto
        </h2>
        <p class="text-base text-muted">Arahkan kamera HP tamu ke kode di layar.</p>

        <div
          class="max-w-full rounded-card bg-canvas p-4 shadow-[0_24px_60px_-30px_rgba(10,19,23,0.45)] sm:p-6"
        >
          <img
            :src="lastQr.qr"
            width="500"
            height="500"
            alt="QR code untuk membuka album foto sesi ini"
            class="h-auto w-[min(500px,80vw,52vh)] max-w-full"
          />
        </div>

        <p class="max-w-full break-all text-sm text-muted">{{ lastQr.url }}</p>
        <button ref="qrClose" type="button" class="quiet-button" @click="qrVisible = false">
          Kembali ke panel
        </button>
      </div>
    </div>
  </div>
</template>
