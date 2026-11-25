<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { lockBodyScroll, unlockBodyScroll } from '@/lib/scrollLock'

const props = defineProps({
  photos: {
    type: Array,
    required: true,
  },
  index: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['close', 'update:index'])
const dialogRef = ref(null)
const closeButton = ref(null)

const current = computed(() => props.photos[props.index])
const hasMultiple = computed(() => props.photos.length > 1)

let previousFocus = null
let touchStart = null

function move(step) {
  emit('update:index', (props.index + step + props.photos.length) % props.photos.length)
}

function onTouchStart(event) {
  if (!hasMultiple.value) {
    return
  }

  const touch = event.changedTouches[0]
  touchStart = { x: touch.clientX, y: touch.clientY }
}

function onTouchEnd(event) {
  if (!touchStart) {
    return
  }

  const touch = event.changedTouches[0]
  const deltaX = touch.clientX - touchStart.x
  const deltaY = touch.clientY - touchStart.y
  touchStart = null

  if (Math.abs(deltaX) < 44 || Math.abs(deltaX) < Math.abs(deltaY) * 1.5) {
    return
  }

  move(deltaX < 0 ? 1 : -1)
}

function onTouchCancel() {
  touchStart = null
}

function focusableItems() {
  if (!dialogRef.value) {
    return []
  }

  return Array.from(dialogRef.value.querySelectorAll('a[href], button:not([disabled])'))
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    emit('close')
    return
  }

  if (event.key === 'Tab') {
    const items = focusableItems()

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

    return
  }

  if (!hasMultiple.value) {
    return
  }

  if (event.key === 'ArrowRight') {
    move(1)
  }

  if (event.key === 'ArrowLeft') {
    move(-1)
  }
}

onMounted(async () => {
  previousFocus = document.activeElement
  window.addEventListener('keydown', onKeydown)
  lockBodyScroll()
  await nextTick()
  closeButton.value?.focus()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  unlockBodyScroll()

  if (previousFocus instanceof HTMLElement) {
    previousFocus.focus()
  }
})
</script>

<template>
  <Teleport to="body">
    <div
      ref="dialogRef"
      class="fixed inset-0 z-50 flex flex-col bg-surface"
      role="dialog"
      aria-modal="true"
      aria-label="Foto sesi"
    >
      <header class="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <p class="text-sm text-muted" aria-live="polite">
          Foto {{ index + 1 }} dari {{ photos.length }}
        </p>
        <button ref="closeButton" type="button" class="quiet-button px-4" @click="emit('close')">
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            class="h-4 w-4"
            aria-hidden="true"
          >
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
          Tutup
        </button>
      </header>

      <div
        class="flex min-h-0 flex-1 touch-pan-y select-none items-center justify-center px-3 pb-3 sm:px-4 sm:pb-4"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
        @touchcancel.passive="onTouchCancel"
      >
        <img
          :src="current.web"
          :alt="`Foto sesi ini, gambar ${index + 1}`"
          width="2000"
          height="1500"
          decoding="async"
          draggable="false"
          class="h-auto max-h-full w-auto max-w-full rounded-photo object-contain shadow-[0_18px_50px_-24px_rgba(10,19,23,0.4)]"
        />
      </div>

      <footer
        class="grid grid-cols-[3rem_1fr_3rem] items-center gap-2 px-4 py-4 sm:flex sm:justify-center sm:gap-3 sm:px-6"
      >
        <button
          type="button"
          class="icon-button"
          :disabled="!hasMultiple"
          aria-label="Sebelumnya"
          @click="move(-1)"
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-5 w-5"
            aria-hidden="true"
          >
            <path d="M15 5l-7 7 7 7" />
          </svg>
        </button>

        <a class="download-button" :href="current.web" :download="current.web.split('/').pop()">
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-4 w-4"
            aria-hidden="true"
          >
            <path d="M12 4v10m0 0l-4-4m4 4l4-4M5 19h14" />
          </svg>
          Unduh foto
        </a>

        <button
          type="button"
          class="icon-button"
          :disabled="!hasMultiple"
          aria-label="Berikutnya"
          @click="move(1)"
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-5 w-5"
            aria-hidden="true"
          >
            <path d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </footer>
    </div>
  </Teleport>
</template>
