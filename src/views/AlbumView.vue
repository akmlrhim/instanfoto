<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useAlbumStore } from '@/stores/album'
import BrandHeader from '@/components/BrandHeader.vue'
import PhotoGrid from '@/components/PhotoGrid.vue'
import Lightbox from '@/components/Lightbox.vue'

const props = defineProps({
  id: {
    type: String,
    required: true,
  },
})

const store = useAlbumStore()
const activeIndex = ref(null)

const lightboxOpen = computed(() => activeIndex.value !== null)

function reload() {
  store.loadAlbum(props.id)
}

watch(() => props.id, reload)
onMounted(reload)
</script>

<template>
  <div class="flex min-h-screen min-h-dvh flex-col bg-canvas">
    <BrandHeader label="Galeri sesi" />

    <main class="album-shell flex-1 pb-16 pt-6 sm:pt-10">
      <div v-if="store.isLoading" aria-live="polite">
        <div class="mb-5 sm:mb-6">
          <h2 class="text-3xl font-medium tracking-tight text-balance sm:text-4xl">
            Foto sesi Anda
          </h2>
          <p class="mt-2 text-sm text-muted">Memuat foto...</p>
        </div>
        <div
          class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 lg:gap-4"
          aria-hidden="true"
        >
          <div
            v-for="placeholder in 8"
            :key="placeholder"
            class="aspect-[4/3] rounded-card bg-surface"
          />
        </div>
      </div>

      <div v-else-if="store.hasError" class="state-card max-w-xl" role="alert">
        <p class="text-xl font-medium tracking-tight text-balance">{{ store.error }}</p>
        <button type="button" class="quiet-button mt-5 w-full sm:w-auto" @click="reload">
          Muat ulang
        </button>
      </div>

      <div v-else-if="store.isEmpty" class="state-card max-w-xl">
        <p class="text-xl font-medium tracking-tight text-balance">
          Belum ada foto untuk sesi ini.
        </p>
        <p class="mt-2 text-sm text-muted">Foto akan muncul setelah fotografer selesai mengirim.</p>
        <button type="button" class="quiet-button mt-5 w-full sm:w-auto" @click="reload">
          Muat ulang
        </button>
      </div>

      <div v-else-if="store.isReady">
        <div class="mb-5 sm:mb-6">
          <h2 class="text-3xl font-medium tracking-tight text-balance sm:text-4xl">
            Foto sesi Anda
          </h2>
          <p class="mt-2 text-sm text-muted sm:flex sm:flex-wrap sm:items-center sm:gap-x-2">
            <span class="block">
              <span class="font-medium text-ink">{{ store.photos.length }}</span> foto dari sesi ini
            </span>
            <span
              class="hidden h-1 w-1 rounded-full bg-disabled-text sm:block"
              aria-hidden="true"
            />
            <span class="mt-1 block sm:mt-0">Ketuk foto untuk memperbesar.</span>
          </p>
        </div>
        <PhotoGrid :photos="store.photos" @open="activeIndex = $event" />
      </div>
    </main>

    <footer class="album-shell border-t border-hairline py-6">
      <p class="text-xs text-muted">Foto disimpan sementara dan dihapus setelah masa simpan.</p>
    </footer>

    <Lightbox
      v-if="lightboxOpen"
      :photos="store.photos"
      :index="activeIndex"
      @update:index="activeIndex = $event"
      @close="activeIndex = null"
    />
  </div>
</template>
