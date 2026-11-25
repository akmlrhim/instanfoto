<script setup>
defineProps({
  photos: {
    type: Array,
    required: true,
  },
})

const emit = defineEmits(['open'])
</script>

<template>
  <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 lg:gap-4">
    <li v-for="(photo, index) in photos" :key="photo.id">
      <button
        type="button"
        class="group relative block aspect-[4/3] w-full cursor-pointer overflow-hidden rounded-card bg-surface"
        :aria-label="`Buka foto ${index + 1}`"
        @click="emit('open', index)"
      >
        <img
          :src="photo.thumb"
          :alt="`Foto sesi ini, gambar ${index + 1}`"
          width="400"
          height="300"
          :loading="index === 0 ? 'eager' : 'lazy'"
          :fetchpriority="index === 0 ? 'high' : undefined"
          decoding="async"
          class="h-full w-full object-cover transition-transform duration-300 ease-out will-change-transform group-hover:scale-[1.02] group-active:scale-[1.01]"
        />
      </button>
    </li>
  </ul>
</template>
