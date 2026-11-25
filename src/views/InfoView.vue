<script setup>
import BrandHeader from '@/components/BrandHeader.vue'
</script>

<template>
  <div class="flex min-h-screen min-h-dvh flex-col bg-canvas">
    <BrandHeader />

    <main
      class="album-shell flex flex-1 flex-col justify-center pb-14 pt-8 sm:justify-start sm:pb-24 sm:pt-20"
    >
      <div class="max-w-3xl">
        <span
          class="grid h-12 w-12 place-items-center rounded-card bg-surface text-ink sm:h-14 sm:w-14"
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-6 w-6"
            aria-hidden="true"
          >
            <rect x="3.5" y="3.5" width="6" height="6" rx="1" />
            <rect x="14.5" y="3.5" width="6" height="6" rx="1" />
            <rect x="3.5" y="14.5" width="6" height="6" rx="1" />
            <path d="M14.5 14.5h2.5V17h-2.5zM20 14.5v1M14.5 20.5h2M19 19h1.5v1.5" />
          </svg>
        </span>

        <h2
          class="mt-6 text-[2.25rem] font-medium leading-[1.14] tracking-tight text-balance sm:mt-8 sm:text-5xl"
        >
          Buka galeri dari QR
        </h2>
        <p
          class="mt-4 max-w-md text-base leading-relaxed text-muted sm:mt-5 sm:max-w-xl sm:text-lg"
        >
          Buka galeri foto melalui QR yang diberikan fotografer setelah sesi selesai.
        </p>
      </div>
    </main>

    <footer class="album-shell border-t border-hairline py-6">
      <p class="text-xs text-muted">Foto disimpan sementara dan dihapus setelah masa simpan.</p>
    </footer>
  </div>
</template>
