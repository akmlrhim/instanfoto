import { defineStore } from 'pinia'

const VALID_ID = /^[a-z0-9]{12}$/

export const useAlbumStore = defineStore('album', {
  state: () => ({
    id: '',
    status: 'idle',
    photos: [],
    error: '',
  }),

  getters: {
    isLoading: (state) => state.status === 'loading',
    isEmpty: (state) => state.status === 'ready' && state.photos.length === 0,
    hasError: (state) => state.status === 'error',
    isReady: (state) => state.status === 'ready' && state.photos.length > 0,
  },

  actions: {
    async loadAlbum(id) {
      this.id = id
      this.status = 'loading'
      this.error = ''
      this.photos = []

      if (!VALID_ID.test(id)) {
        this.status = 'error'
        this.error = 'Album tidak ditemukan.'
        return
      }

      try {
        const response = await fetch(`/api/albums/${encodeURIComponent(id)}`)

        if (response.status === 404) {
          this.status = 'error'
          this.error = 'Album tidak ditemukan.'
          return
        }

        if (!response.ok) {
          throw new Error('Gagal memuat album')
        }

        const data = await response.json()

        if (!Array.isArray(data.photos)) {
          throw new Error('Gagal memuat album')
        }

        this.photos = data.photos
        this.status = 'ready'
      } catch {
        this.status = 'error'
        this.error = 'Gagal memuat album. Periksa koneksi lalu muat ulang.'
      }
    },
  },
})
