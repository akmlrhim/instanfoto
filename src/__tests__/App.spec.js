import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AlbumView from '@/views/AlbumView.vue'
import InfoView from '@/views/InfoView.vue'
import { useAlbumStore } from '@/stores/album'

describe('InstanFoto gallery', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('menolak ID album yang tidak valid', async () => {
    const store = useAlbumStore()
    await store.loadAlbum('../../etc')
    expect(store.hasError).toBe(true)
    expect(store.error).toBe('Album tidak ditemukan.')
  })

  it('halaman info tidak memuat akun, login, atau pembayaran', () => {
    const wrapper = mount(InfoView)
    const text = wrapper.text().toLowerCase()
    expect(text).toContain('instanfoto')
    expect(text).not.toContain('login')
    expect(text).not.toContain('pembayaran')
    expect(text).not.toContain('daftar')
  })

  it('AlbumView menampilkan status memuat dan pemberitahuan masa simpan', async () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
    const wrapper = mount(AlbumView, { props: { id: 'aaaaaaaaaaaa' } })
    await nextTick()
    expect(wrapper.text()).toContain('Memuat foto...')
    expect(wrapper.text()).toContain('dihapus setelah masa simpan')
    vi.unstubAllGlobals()
  })
})
