<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import LoadingState from '../components/LoadingState.vue';
import PageHeader from '../components/PageHeader.vue';
import { GALLERY_REFRESH_REQUESTED_EVENT } from '../galleryEvents';
import MobilePageShell from '../layouts/MobilePageShell.vue';
import { fetchGalleryPhotos, updatePhotoLike, type GalleryPhoto } from '../services/galleryPhotos';
import { useUserIdentityStore } from '../stores/userIdentity';

type GalleryStatus = 'idle' | 'loading' | 'ready' | 'error';

const userIdentity = useUserIdentityStore();
const photos = ref<GalleryPhoto[]>([]);
const galleryStatus = ref<GalleryStatus>('idle');
const nextCursor = ref<string | null>(null);
const hasMore = ref(false);
const totalCount = ref(0);
const isLoadingMore = ref(false);
const isRefreshing = ref(false);
const refreshError = ref('');
const loadMoreError = ref('');
const loadMoreSentinel = ref<HTMLElement | null>(null);
const loadedThumbnailIds = ref<Set<number>>(new Set());
const failedThumbnailIds = ref<Set<number>>(new Set());
const selectedPhoto = ref<GalleryPhoto | null>(null);
const hasPreviewError = ref(false);
const pendingLikePhotoIds = ref<Set<number>>(new Set());
const likeError = ref('');
const PULL_REFRESH_THRESHOLD = 72;
const MAX_PULL_DISTANCE = 112;
const pullDistance = ref(0);
const isPulling = ref(false);
const isPullReady = computed(() => pullDistance.value >= PULL_REFRESH_THRESHOLD);
let pullStartY: number | null = null;
let hasBeenActivated = false;
let shouldRefreshAfterCurrentLoad = false;
let loadMoreObserver: IntersectionObserver | null = null;

watch(
    () => userIdentity.isAuthenticated,
    (isAuthenticated) => {
        if (isAuthenticated && galleryStatus.value === 'idle') {
            void loadGallery();
        }

        if (!isAuthenticated) {
            resetGallery();
        }
    },
    { immediate: true },
);

watch(loadMoreSentinel, () => {
    observeLoadMoreSentinel();
});

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    loadMoreObserver = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                void loadMorePhotos();
            }
        },
        { rootMargin: '320px 0px' },
    );
    observeLoadMoreSentinel();
});

onActivated(() => {
    window.addEventListener(GALLERY_REFRESH_REQUESTED_EVENT, handleRequestedGalleryRefresh);

    if (hasBeenActivated) {
        void refreshGallery();
    } else {
        hasBeenActivated = true;
    }
});

onDeactivated(() => {
    window.removeEventListener(GALLERY_REFRESH_REQUESTED_EVENT, handleRequestedGalleryRefresh);
    loadMoreObserver?.disconnect();
    cancelPullRefresh();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener(GALLERY_REFRESH_REQUESTED_EVENT, handleRequestedGalleryRefresh);
    loadMoreObserver?.disconnect();
});

async function loadGallery(): Promise<void> {
    galleryStatus.value = 'loading';
    loadMoreError.value = '';

    try {
        const page = await fetchGalleryPhotos();

        photos.value = page.photos;
        nextCursor.value = page.next_cursor;
        hasMore.value = page.has_more;
        totalCount.value = page.total_count;
        loadedThumbnailIds.value = new Set();
        failedThumbnailIds.value = new Set();
        galleryStatus.value = 'ready';
    } catch {
        galleryStatus.value = 'error';
    }

    if (shouldRefreshAfterCurrentLoad) {
        shouldRefreshAfterCurrentLoad = false;
        void refreshGallery();
    }
}

async function refreshGallery(): Promise<void> {
    if (!userIdentity.isAuthenticated || isRefreshing.value) {
        return;
    }

    if (galleryStatus.value === 'loading' || isLoadingMore.value) {
        shouldRefreshAfterCurrentLoad = true;

        return;
    }

    if (galleryStatus.value !== 'ready') {
        await loadGallery();

        return;
    }

    isRefreshing.value = true;
    refreshError.value = '';
    loadMoreError.value = '';
    loadMoreObserver?.disconnect();

    try {
        const page = await fetchGalleryPhotos();

        photos.value = page.photos;
        nextCursor.value = page.next_cursor;
        hasMore.value = page.has_more;
        totalCount.value = page.total_count;
        failedThumbnailIds.value = new Set();
    } catch {
        refreshError.value = '刷新失败，请稍后重试。';
    } finally {
        isRefreshing.value = false;
        await nextTick();
        observeLoadMoreSentinel();
    }
}

async function loadMorePhotos(): Promise<void> {
    if (!hasMore.value || nextCursor.value === null || isLoadingMore.value) {
        return;
    }

    isLoadingMore.value = true;
    loadMoreError.value = '';
    loadMoreObserver?.disconnect();

    try {
        const page = await fetchGalleryPhotos(nextCursor.value);

        photos.value = [...photos.value, ...page.photos];
        nextCursor.value = page.next_cursor;
        hasMore.value = page.has_more;
        totalCount.value = page.total_count;
    } catch {
        loadMoreError.value = '更多照片加载失败，请重试。';
    } finally {
        isLoadingMore.value = false;
        await nextTick();

        if (shouldRefreshAfterCurrentLoad) {
            shouldRefreshAfterCurrentLoad = false;
            await refreshGallery();
        } else if (loadMoreError.value === '') {
            observeLoadMoreSentinel();
        }
    }
}

function observeLoadMoreSentinel(): void {
    loadMoreObserver?.disconnect();

    if (
        loadMoreObserver === null
        || loadMoreSentinel.value === null
        || !hasMore.value
        || isLoadingMore.value
        || loadMoreError.value !== ''
    ) {
        return;
    }

    loadMoreObserver.observe(loadMoreSentinel.value);
}

function resetGallery(): void {
    photos.value = [];
    nextCursor.value = null;
    hasMore.value = false;
    totalCount.value = 0;
    galleryStatus.value = 'idle';
    isRefreshing.value = false;
    refreshError.value = '';
    loadMoreError.value = '';
    loadedThumbnailIds.value = new Set();
    failedThumbnailIds.value = new Set();
    selectedPhoto.value = null;
    pendingLikePhotoIds.value = new Set();
    likeError.value = '';
    shouldRefreshAfterCurrentLoad = false;
    cancelPullRefresh();
}

function handleRequestedGalleryRefresh(): void {
    void refreshGallery();
}

function handlePullStart(event: TouchEvent): void {
    if (
        event.touches.length !== 1
        || window.scrollY > 0
        || isRefreshing.value
        || galleryStatus.value === 'loading'
        || selectedPhoto.value !== null
    ) {
        pullStartY = null;

        return;
    }

    pullStartY = event.touches[0]?.clientY ?? null;
}

function handlePullMove(event: TouchEvent): void {
    const currentY = event.touches[0]?.clientY;

    if (pullStartY === null || currentY === undefined) {
        return;
    }

    const draggedDistance = currentY - pullStartY;

    if (draggedDistance <= 0 || window.scrollY > 0) {
        cancelPullRefresh();

        return;
    }

    event.preventDefault();
    isPulling.value = true;
    pullDistance.value = Math.min(MAX_PULL_DISTANCE, draggedDistance * 0.45);
}

function handlePullEnd(): void {
    const shouldRefresh = isPullReady.value;

    cancelPullRefresh();

    if (shouldRefresh) {
        void refreshGallery();
    }
}

function cancelPullRefresh(): void {
    pullStartY = null;
    isPulling.value = false;
    pullDistance.value = 0;
}

function openPreview(photo: GalleryPhoto): void {
    selectedPhoto.value = photo;
    hasPreviewError.value = false;
}

function closePreview(): void {
    selectedPhoto.value = null;
    hasPreviewError.value = false;
}

function markThumbnailFailed(photoId: number): void {
    failedThumbnailIds.value = new Set([...failedThumbnailIds.value, photoId]);
}

function markThumbnailLoaded(photoId: number): void {
    loadedThumbnailIds.value = new Set([...loadedThumbnailIds.value, photoId]);
}

async function togglePhotoLike(photo: GalleryPhoto): Promise<void> {
    if (!photo.can_like || pendingLikePhotoIds.value.has(photo.id)) {
        return;
    }

    const previousLiked = photo.liked_by_me;
    const previousCount = photo.like_count;
    const nextLiked = !previousLiked;
    const nextCount = Math.max(0, previousCount + (nextLiked ? 1 : -1));

    likeError.value = '';
    pendingLikePhotoIds.value = new Set([...pendingLikePhotoIds.value, photo.id]);
    updatePhotoLikeState(photo.id, nextLiked, nextCount);

    try {
        await updatePhotoLike(photo.id, nextLiked);
    } catch {
        updatePhotoLikeState(photo.id, previousLiked, previousCount);
        likeError.value = '点赞操作失败，请稍后重试。';
    } finally {
        const pendingIds = new Set(pendingLikePhotoIds.value);
        pendingIds.delete(photo.id);
        pendingLikePhotoIds.value = pendingIds;
    }
}

function updatePhotoLikeState(photoId: number, liked: boolean, likeCount: number): void {
    photos.value = photos.value.map((photo) => photo.id === photoId
        ? { ...photo, liked_by_me: liked, like_count: likeCount }
        : photo);

    if (selectedPhoto.value?.id === photoId) {
        selectedPhoto.value = {
            ...selectedPhoto.value,
            liked_by_me: liked,
            like_count: likeCount,
        };
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && selectedPhoto.value !== null) {
        closePreview();
    }
}
</script>

<template>
    <MobilePageShell>
        <div
            :class="['relative flex flex-col gap-section', isPulling ? '' : 'transition-transform duration-200 ease-out']"
            :style="{ transform: `translateY(${pullDistance}px)` }"
            @touchstart="handlePullStart"
            @touchmove="handlePullMove"
            @touchend="handlePullEnd"
            @touchcancel="cancelPullRefresh"
        >
            <div
                v-if="isPulling"
                class="pointer-events-none absolute inset-x-0 -top-7 flex items-center justify-center text-xs font-medium text-ink-500"
                aria-hidden="true"
            >
                <span>{{ isPullReady ? '松开即可刷新' : '下拉刷新照片' }}</span>
            </div>

            <PageHeader eyebrow="共享回忆" title="亲友照片墙" description="审核通过的婚礼瞬间会出现在这里，大家可以为喜欢的照片送上心意。" />

            <section class="rounded-card border border-white/80 bg-white/72 p-5 shadow-card backdrop-blur-sm">
            <div class="flex items-center justify-between gap-4">
                <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-1">
                    <p class="whitespace-nowrap text-sm font-semibold text-ink-950">照片正在汇集</p>
                    <p class="whitespace-nowrap text-xs text-ink-500">点击照片可以查看清晰大图</p>
                </div>
                <span v-if="galleryStatus === 'ready'" class="shrink-0 whitespace-nowrap rounded-pill bg-gold-100 px-3 py-1.5 text-xs font-semibold text-gold-500">
                    {{ isRefreshing ? '正在刷新…' : `已有 ${totalCount} 张` }}
                </span>
            </div>
            <p v-if="refreshError" class="mt-3 text-center text-sm text-rose-700" role="alert">{{ refreshError }}</p>
            </section>

            <section v-if="galleryStatus === 'idle' || galleryStatus === 'loading'" class="grid grid-cols-2 gap-3" aria-label="正在加载照片墙">
            <div
                v-for="index in 4"
                :key="index"
                class="relative aspect-square animate-pulse overflow-hidden rounded-card border border-white/75 bg-white/55 shadow-card"
            >
                <div class="absolute inset-0 bg-linear-to-br from-rose-50 via-paper to-gold-100/80"></div>
                <div class="absolute inset-x-4 bottom-4 h-2 rounded-pill bg-white/80"></div>
            </div>
            </section>

            <section v-else-if="galleryStatus === 'error'" class="flex min-h-56 flex-col items-center justify-center gap-4 rounded-card border border-white/80 bg-white/72 p-6 text-center shadow-card">
            <span class="grid size-14 place-items-center rounded-full bg-rose-50 text-xl text-rose-600" aria-hidden="true">!</span>
            <div class="flex flex-col gap-2">
                <h2 class="font-display text-2xl font-semibold text-ink-950">照片暂时加载失败</h2>
                <p class="text-sm leading-6 text-ink-500">请检查网络后重新加载。</p>
            </div>
            <BaseButton variant="secondary" @click="loadGallery">重新加载</BaseButton>
            </section>

            <section v-else-if="photos.length === 0" class="flex min-h-56 flex-col items-center justify-center gap-4 rounded-card border border-white/80 bg-white/72 p-6 text-center shadow-card">
            <span class="grid size-14 place-items-center rounded-full bg-gold-100 font-display text-xl text-gold-500" aria-hidden="true">囍</span>
            <div class="flex flex-col gap-2">
                <h2 class="font-display text-2xl font-semibold text-ink-950">照片正在路上</h2>
                <p class="text-sm leading-6 text-ink-500">审核通过的婚礼瞬间会出现在这里。</p>
            </div>
            </section>

            <template v-else>
            <section class="grid grid-cols-2 gap-3" aria-label="婚礼照片墙">
                <article
                    v-for="photo in photos"
                    :key="photo.id"
                    class="relative aspect-square overflow-hidden rounded-card border border-white/80 bg-rose-50 shadow-card"
                >
                    <button
                        type="button"
                        class="group relative block size-full text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-rose-500"
                        aria-label="查看婚礼照片大图"
                        @click="openPreview(photo)"
                    >
                        <span v-if="failedThumbnailIds.has(photo.id)" class="grid size-full place-items-center px-3 text-center text-xs text-ink-500">照片加载失败</span>
                        <template v-else>
                            <span
                                v-if="!loadedThumbnailIds.has(photo.id)"
                                class="absolute inset-0 flex items-center justify-center gap-2 bg-linear-to-br from-rose-50 via-paper to-gold-100/80 text-xs font-medium text-ink-500"
                                aria-hidden="true"
                            >
                                <span class="size-3.5 animate-spin rounded-full border-2 border-rose-100 border-t-rose-600"></span>
                                <span>加载中</span>
                            </span>
                            <img
                                :src="photo.thumbnail_url"
                                alt="婚礼现场照片"
                                :class="[
                                    'size-full object-cover transition duration-300 group-active:scale-[1.02]',
                                    loadedThumbnailIds.has(photo.id) ? 'opacity-100' : 'opacity-0',
                                ]"
                                loading="lazy"
                                decoding="async"
                                @load="markThumbnailLoaded(photo.id)"
                                @error="markThumbnailFailed(photo.id)"
                            />
                        </template>
                    </button>
                    <span class="pointer-events-none absolute inset-0 ring-1 ring-inset ring-white/25" aria-hidden="true"></span>

                    <span
                        v-if="photo.is_mine"
                        class="absolute right-2 bottom-2 rounded-pill bg-ink-950/58 px-2.5 py-1.5 text-[0.68rem] font-semibold text-white backdrop-blur-sm"
                    >
                        我的照片
                    </span>
                    <button
                        v-else
                        type="button"
                        :class="[
                            'absolute right-2 bottom-2 inline-flex min-h-9 items-center gap-1.5 rounded-pill px-3 py-1.5 text-xs font-semibold shadow-sm backdrop-blur-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-wait disabled:opacity-65',
                            photo.liked_by_me ? 'bg-rose-600 text-white' : 'bg-white/88 text-ink-700',
                        ]"
                        :disabled="pendingLikePhotoIds.has(photo.id)"
                        :aria-label="photo.liked_by_me ? '取消点赞' : '点赞'"
                        :aria-pressed="photo.liked_by_me"
                        @click="togglePhotoLike(photo)"
                    >
                        <span aria-hidden="true">♥</span>
                        <span>{{ photo.like_count }}</span>
                    </button>
                </article>
            </section>

            <section ref="loadMoreSentinel" class="flex min-h-10 flex-col items-center gap-3 text-center">
                <LoadingState v-if="isLoadingMore" label="正在加载更多照片" />
                <template v-else-if="loadMoreError">
                    <p class="text-sm text-rose-700" role="alert">{{ loadMoreError }}</p>
                    <BaseButton variant="secondary" @click="loadMorePhotos">重新加载</BaseButton>
                </template>
                <p v-else-if="!hasMore" class="text-xs text-ink-500">已经看完全部照片啦</p>
                <p v-if="likeError" class="text-sm text-rose-700" role="alert">{{ likeError }}</p>
            </section>
            </template>
        </div>

        <Teleport to="body">
            <div
                v-if="selectedPhoto !== null"
                class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/92 p-3 backdrop-blur-sm sm:p-8"
                role="dialog"
                aria-modal="true"
                aria-label="照片大图预览"
                @click.self="closePreview"
            >
                <button
                    type="button"
                    class="absolute top-4 right-4 z-10 grid size-11 place-items-center rounded-full bg-white/15 text-2xl text-white backdrop-blur-sm transition hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    aria-label="关闭大图预览"
                    @click="closePreview"
                >
                    ×
                </button>

                <img
                    v-if="!hasPreviewError"
                    :src="selectedPhoto.preview_url"
                    alt="婚礼现场照片大图"
                    class="max-h-full max-w-full rounded-control object-contain shadow-2xl"
                    @error="hasPreviewError = true"
                />
                <span
                    v-if="!hasPreviewError && selectedPhoto.is_mine"
                    class="absolute bottom-5 left-1/2 -translate-x-1/2 rounded-pill bg-white/16 px-4 py-2 text-sm font-semibold text-white backdrop-blur-md"
                >
                    我的照片 · {{ selectedPhoto.like_count }} 个赞
                </span>
                <button
                    v-else-if="!hasPreviewError"
                    type="button"
                    :class="[
                        'absolute bottom-5 left-1/2 inline-flex min-h-11 -translate-x-1/2 items-center gap-2 rounded-pill px-5 py-2.5 text-sm font-semibold shadow-lg backdrop-blur-md transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-wait disabled:opacity-65',
                        selectedPhoto.liked_by_me ? 'bg-rose-600 text-white' : 'bg-white/90 text-ink-950',
                    ]"
                    :disabled="pendingLikePhotoIds.has(selectedPhoto.id)"
                    :aria-pressed="selectedPhoto.liked_by_me"
                    @click="togglePhotoLike(selectedPhoto)"
                >
                    <span aria-hidden="true">♥</span>
                    <span>{{ selectedPhoto.liked_by_me ? '已点赞' : '点赞' }} · {{ selectedPhoto.like_count }}</span>
                </button>
                <div v-else class="flex max-w-xs flex-col items-center gap-4 rounded-card bg-paper p-6 text-center shadow-shell">
                    <span class="grid size-14 place-items-center rounded-full bg-rose-50 text-xl text-rose-600" aria-hidden="true">!</span>
                    <p class="text-sm leading-6 text-ink-700">大图暂时加载失败，请稍后重试。</p>
                    <BaseButton variant="secondary" @click="closePreview">关闭</BaseButton>
                </div>
            </div>
        </Teleport>
    </MobilePageShell>
</template>
