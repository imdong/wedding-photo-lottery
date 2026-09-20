import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'upload',
        component: () => import('../views/UploadView.vue'),
        meta: { keepAlive: true },
    },
    {
        path: '/gallery',
        name: 'gallery',
        component: () => import('../views/GalleryView.vue'),
        meta: { keepAlive: true },
    },
    {
        path: '/draw',
        name: 'draw',
        component: () => import('../views/DrawView.vue'),
    },
    {
        path: '/admin/review',
        name: 'admin-review',
        component: () => import('../views/AdminReviewView.vue'),
    },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
