import { createRouter, createWebHistory } from 'vue-router'
import AlbumView from '@/views/AlbumView.vue'
import InfoView from '@/views/InfoView.vue'
import PanelView from '@/views/PanelView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/panel', name: 'panel', component: PanelView },
    { path: '/a/:id', name: 'album', component: AlbumView, props: true },
    { path: '/:pathMatch(.*)*', name: 'info', component: InfoView },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

export default router
