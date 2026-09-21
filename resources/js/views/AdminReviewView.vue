<script setup lang="ts">
import { isAxiosError } from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import LoadingState from '../components/LoadingState.vue';
import PageHeader from '../components/PageHeader.vue';
import DesktopPageShell from '../layouts/DesktopPageShell.vue';
import {
    adminTokenStorageKey,
    fetchNextAdminReview,
    loginAdmin,
    reviewAdminPhoto,
    undoLatestAdminReview,
    verifyAdminSession,
    type AdminPhotoReviewStatus,
    type AdminReviewPhoto,
} from '../services/adminReviews';

interface ApiErrorResponse {
    code?: string;
    errors?: Record<string, string[]>;
    message?: string;
}

type AuthenticationStatus = 'restoring' | 'guest' | 'authenticated';
type ReviewQueueStatus = 'idle' | 'loading' | 'ready' | 'empty' | 'error';

const authenticationStatus = ref<AuthenticationStatus>('restoring');
const reviewQueueStatus = ref<ReviewQueueStatus>('idle');
const adminToken = ref('');
const password = ref('');
const loginError = ref('');
const actionError = ref('');
const actionMessage = ref('');
const currentPhoto = ref<AdminReviewPhoto | null>(null);
const prefetchedNextPhoto = ref<AdminReviewPhoto | null>(null);
const remainingCount = ref(0);
const isLoggingIn = ref(false);
const isSubmittingReview = ref(false);
const hasImageError = ref(false);
let latestReviewLoadSequence = 0;

onMounted(() => {
    window.addEventListener('keydown', handleReviewShortcut);
    void restoreAdminSession();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleReviewShortcut);
});

async function restoreAdminSession(): Promise<void> {
    const storedToken = localStorage.getItem(adminTokenStorageKey);

    if (storedToken === null) {
        authenticationStatus.value = 'guest';

        return;
    }

    try {
        await verifyAdminSession(storedToken);
        adminToken.value = storedToken;
        authenticationStatus.value = 'authenticated';
        await loadNextReview();
    } catch (error: unknown) {
        if (isUnauthorized(error)) {
            clearAdminSession();
        } else {
            authenticationStatus.value = 'guest';
            loginError.value = '暂时无法连接，请稍后重试。';
        }
    }
}

async function submitLogin(): Promise<void> {
    if (password.value === '' || isLoggingIn.value) {
        return;
    }

    isLoggingIn.value = true;
    loginError.value = '';

    try {
        const session = await loginAdmin(password.value);

        localStorage.setItem(adminTokenStorageKey, session.token);
        adminToken.value = session.token;
        password.value = '';
        authenticationStatus.value = 'authenticated';
        await loadNextReview();
    } catch (error: unknown) {
        loginError.value = getApiErrorMessage(error, '登录失败，请稍后重试。');
    } finally {
        isLoggingIn.value = false;
    }
}

async function loadNextReview(options?: { preloadImage?: boolean; preserveCurrentPhoto?: boolean }): Promise<void> {
    if (adminToken.value === '') {
        return;
    }

    const loadSequence = ++latestReviewLoadSequence;
    const shouldPreserveCurrentPhoto = options?.preserveCurrentPhoto === true;

    if (!shouldPreserveCurrentPhoto) {
        reviewQueueStatus.value = 'loading';
        currentPhoto.value = null;
        prefetchedNextPhoto.value = null;
    }

    actionError.value = '';
    hasImageError.value = false;

    try {
        const queue = await fetchNextAdminReview(adminToken.value);

        if (loadSequence !== latestReviewLoadSequence) {
            return;
        }

        if (options?.preloadImage !== false) {
            await preloadReviewImages(queue.photo, queue.next_photo);

            if (loadSequence !== latestReviewLoadSequence) {
                return;
            }
        }

        currentPhoto.value = queue.photo;
        prefetchedNextPhoto.value = queue.next_photo;
        remainingCount.value = queue.remaining_count;
        reviewQueueStatus.value = queue.photo === null ? 'empty' : 'ready';
    } catch (error: unknown) {
        if (isUnauthorized(error)) {
            expireAdminSession();

            return;
        }

        reviewQueueStatus.value = 'error';
        actionError.value = '照片暂时加载失败，请稍后重试。';
    }
}

async function submitReview(reviewStatus: AdminPhotoReviewStatus): Promise<void> {
    if (currentPhoto.value === null || isSubmittingReview.value || adminToken.value === '') {
        return;
    }

    isSubmittingReview.value = true;
    actionError.value = '';
    actionMessage.value = '';

    try {
        await reviewAdminPhoto(adminToken.value, currentPhoto.value.id, reviewStatus);

        const immediateNextPhoto = prefetchedNextPhoto.value;

        if (immediateNextPhoto !== null) {
            currentPhoto.value = immediateNextPhoto;
            prefetchedNextPhoto.value = null;
            remainingCount.value = Math.max(remainingCount.value - 1, 0);
            hasImageError.value = false;
            reviewQueueStatus.value = 'ready';

            await loadNextReview({ preserveCurrentPhoto: true });

            return;
        }

        await loadNextReview({ preloadImage: true, preserveCurrentPhoto: true });
    } catch (error: unknown) {
        if (isUnauthorized(error)) {
            expireAdminSession();

            return;
        }

        if (isAxiosError<ApiErrorResponse>(error) && error.response?.status === 409) {
            actionMessage.value = '照片状态已更新，已为你刷新队列。';
            await loadNextReview({ preloadImage: true, preserveCurrentPhoto: true });

            return;
        }

        actionError.value = getApiErrorMessage(error, '审核失败，请稍后重试。');
    } finally {
        isSubmittingReview.value = false;
    }
}

async function undoLatestReview(): Promise<void> {
    if (isSubmittingReview.value || reviewQueueStatus.value === 'loading' || adminToken.value === '') {
        return;
    }

    isSubmittingReview.value = true;
    actionError.value = '';
    actionMessage.value = '';

    try {
        await undoLatestAdminReview(adminToken.value);
        actionMessage.value = '已撤销最近一次审核。';
        await loadNextReview({ preloadImage: true, preserveCurrentPhoto: true });
    } catch (error: unknown) {
        if (isUnauthorized(error)) {
            expireAdminSession();

            return;
        }

        actionError.value = getApiErrorMessage(error, '暂时无法撤销，请稍后重试。');
    } finally {
        isSubmittingReview.value = false;
    }
}

function preloadReviewImage(url: string): Promise<void> {
    return new Promise((resolve) => {
        const image = new Image();

        image.onload = () => {
            resolve();
        };

        image.onerror = () => {
            resolve();
        };

        image.src = url;
    });
}

function preloadReviewImages(
    currentPhotoToLoad: AdminReviewPhoto | null,
    nextPhotoToLoad: AdminReviewPhoto | null,
): Promise<void> {
    const reviewUrls = [currentPhotoToLoad?.review_url, nextPhotoToLoad?.review_url]
        .filter((url): url is string => typeof url === 'string' && url !== '');

    if (reviewUrls.length === 0) {
        return Promise.resolve();
    }

    return Promise.all(reviewUrls.map((url) => preloadReviewImage(url))).then(() => undefined);
}

function handleReviewShortcut(event: KeyboardEvent): void {
    if (
        event.repeat
        || event.ctrlKey
        || event.metaKey
        || event.altKey
        || authenticationStatus.value !== 'authenticated'
        || isSubmittingReview.value
        || reviewQueueStatus.value === 'loading'
        || isEditableElement(event.target)
    ) {
        return;
    }

    const reviewShortcuts: Record<string, AdminPhotoReviewStatus> = {
        '1': 'normal',
        '2': 'good',
        '0': 'rejected',
    };
    const reviewStatus = reviewShortcuts[event.key];

    if (reviewStatus !== undefined) {
        if (currentPhoto.value === null || hasImageError.value) {
            return;
        }

        event.preventDefault();
        void submitReview(reviewStatus);

        return;
    }

    if (event.key.toLowerCase() === 'u') {
        event.preventDefault();
        void undoLatestReview();
    }
}

function isEditableElement(target: EventTarget | null): boolean {
    return target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

function expireAdminSession(): void {
    clearAdminSession();
    loginError.value = '登录已过期，请重新登录。';
}

function clearAdminSession(): void {
    localStorage.removeItem(adminTokenStorageKey);
    adminToken.value = '';
    currentPhoto.value = null;
    prefetchedNextPhoto.value = null;
    remainingCount.value = 0;
    authenticationStatus.value = 'guest';
    reviewQueueStatus.value = 'idle';
}

function isUnauthorized(error: unknown): boolean {
    return isAxiosError(error) && error.response?.status === 401;
}

function getApiErrorMessage(error: unknown, fallback: string): string {
    if (!isAxiosError<ApiErrorResponse>(error)) {
        return fallback;
    }

    const validationMessage = Object.values(error.response?.data.errors ?? {})
        .flat()
        .find((message) => message.trim() !== '');

    return validationMessage ?? error.response?.data.message ?? fallback;
}
</script>

<template>
    <DesktopPageShell section-label="照片审核">
        <PageHeader eyebrow="Review Desk" title="快速审核现场照片" description="逐张确认照片是否适合进入照片墙，并为通过的照片设置抽奖等级。" />

        <section v-if="authenticationStatus === 'restoring'" class="grid min-h-[32rem] place-items-center rounded-card border border-white/85 bg-white/68 shadow-card backdrop-blur-sm">
            <LoadingState label="正在进入审核台" />
        </section>

        <section v-else-if="authenticationStatus === 'guest'" class="mx-auto w-full max-w-lg rounded-card border border-white/85 bg-white/75 p-8 shadow-card backdrop-blur-sm">
            <form class="flex flex-col gap-6" @submit.prevent="submitLogin">
                <div class="flex flex-col gap-2 text-center">
                    <h2 class="font-display text-3xl font-semibold text-ink-950">进入审核台</h2>
                    <p class="text-sm leading-6 text-ink-500">请输入管理员密码后开始审核。</p>
                </div>

                <label class="flex flex-col gap-2 text-sm font-semibold text-ink-700">
                    管理员密码
                    <input
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        class="min-h-12 rounded-control border border-rose-100 bg-white px-4 text-base font-normal text-ink-950 outline-none transition placeholder:text-ink-500/65 focus:border-rose-300 focus:ring-3 focus:ring-rose-100"
                        placeholder="请输入密码"
                        :disabled="isLoggingIn"
                    />
                </label>

                <p v-if="loginError" class="rounded-control bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
                    {{ loginError }}
                </p>

                <BaseButton native-type="submit" full-width :disabled="password === '' || isLoggingIn">
                    {{ isLoggingIn ? '正在登录…' : '进入审核台' }}
                </BaseButton>
            </form>
        </section>

        <section v-else class="grid gap-3 lg:gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(20rem,0.55fr)]">
            <div class="relative grid min-h-[20rem] overflow-hidden rounded-card border border-white/85 bg-white/68 shadow-card backdrop-blur-sm sm:min-h-[26rem] lg:min-h-[42rem]">
                <div v-if="reviewQueueStatus === 'loading'" class="grid place-items-center p-8">
                    <LoadingState label="正在加载下一张照片" />
                </div>

                <div v-else-if="reviewQueueStatus === 'error'" class="grid place-items-center p-8">
                    <div class="flex max-w-sm flex-col items-center gap-4 text-center">
                        <span class="grid size-16 place-items-center rounded-full bg-rose-50 text-2xl text-rose-600" aria-hidden="true">!</span>
                        <h2 class="font-display text-3xl font-semibold text-ink-950">照片加载失败</h2>
                        <p class="leading-7 text-ink-500">请检查网络后重新加载。</p>
                        <BaseButton variant="secondary" @click="loadNextReview">重新加载</BaseButton>
                    </div>
                </div>

                <div v-else-if="reviewQueueStatus === 'empty'" class="grid place-items-center p-8">
                    <div class="flex max-w-sm flex-col items-center gap-4 text-center">
                        <span class="grid size-16 place-items-center rounded-full bg-sage-100 text-2xl text-sage-600" aria-hidden="true">✓</span>
                        <h2 class="font-display text-3xl font-semibold text-ink-950">暂无待审核照片</h2>
                        <p class="leading-7 text-ink-500">宾客上传后，照片会按时间顺序出现在这里。</p>
                        <BaseButton variant="secondary" @click="loadNextReview">刷新队列</BaseButton>
                    </div>
                </div>

                <template v-else-if="currentPhoto !== null">
                    <img
                        v-if="!hasImageError"
                        :src="currentPhoto.review_url"
                        :alt="currentPhoto.original_filename"
                        class="absolute inset-0 size-full bg-ink-950/5 object-contain"
                        @error="hasImageError = true"
                    />
                    <div v-else class="grid place-items-center p-8 text-center">
                        <div class="flex max-w-sm flex-col items-center gap-4">
                            <span class="grid size-16 place-items-center rounded-full bg-rose-50 text-2xl text-rose-600" aria-hidden="true">!</span>
                            <h2 class="font-display text-3xl font-semibold text-ink-950">照片显示失败</h2>
                            <p class="leading-7 text-ink-500">可刷新后重试，或稍后再处理这张照片。</p>
                            <BaseButton variant="secondary" @click="loadNextReview">重新加载</BaseButton>
                        </div>
                    </div>

                    <div class="absolute inset-x-0 bottom-0 flex flex-wrap items-end justify-between gap-3 bg-linear-to-t from-ink-950/80 via-ink-950/45 to-transparent px-4 pt-16 pb-4 text-white sm:px-6 sm:pt-20 sm:pb-5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ currentPhoto.original_filename }}</p>
                            <p class="mt-1 text-xs text-white/75">上传者手机尾号 {{ currentPhoto.uploader_phone_suffix }}</p>
                        </div>
                        <span class="rounded-pill bg-white/18 px-3 py-1.5 text-xs font-semibold backdrop-blur-sm">
                            {{ currentPhoto.width && currentPhoto.height ? `${currentPhoto.width} × ${currentPhoto.height}` : '原图' }}
                        </span>
                    </div>
                </template>
            </div>

            <aside class="flex flex-col gap-3 rounded-card border border-white/85 bg-paper/85 p-4 shadow-card backdrop-blur-sm sm:p-5 lg:gap-5 lg:p-6">
                <div class="flex items-center justify-between gap-2 border-b border-rose-100 pb-3 lg:gap-4 lg:pb-5">
                    <div class="flex items-baseline gap-2">
                        <p class="text-[11px] font-semibold tracking-[0.14em] text-rose-600 uppercase lg:text-xs lg:tracking-[0.2em]">待处理</p>
                        <p class="font-display text-2xl leading-none font-semibold text-ink-950 lg:text-4xl">{{ remainingCount }}</p>
                    </div>
                    <span :class="['rounded-pill px-2.5 py-1 text-[11px] font-semibold lg:px-3 lg:py-1.5 lg:text-xs', remainingCount === 0 ? 'bg-sage-100 text-sage-600' : 'bg-gold-100 text-gold-500']">
                        {{ remainingCount === 0 ? '队列已清空' : '张照片' }}
                    </span>
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold text-ink-700">审核操作</p>
                        <span class="hidden text-xs text-ink-500 lg:inline">可直接按键</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 lg:flex lg:flex-col lg:gap-3">
                        <BaseButton
                            full-width
                            variant="secondary"
                            aria-keyshortcuts="1"
                            :disabled="currentPhoto === null || isSubmittingReview || hasImageError"
                            @click="submitReview('normal')"
                        >
                            <span class="lg:hidden">普通</span>
                            <span class="hidden lg:inline">普通 · 1 倍权重</span>
                            <kbd class="hidden rounded-md bg-rose-50 px-2 py-0.5 font-sans text-xs text-rose-700 lg:inline">1</kbd>
                        </BaseButton>
                        <BaseButton
                            full-width
                            aria-keyshortcuts="2"
                            :disabled="currentPhoto === null || isSubmittingReview || hasImageError"
                            @click="submitReview('good')"
                        >
                            <span class="lg:hidden">好</span>
                            <span class="hidden lg:inline">好 · 2 倍权重</span>
                            <kbd class="hidden rounded-md bg-white/18 px-2 py-0.5 font-sans text-xs text-white lg:inline">2</kbd>
                        </BaseButton>
                        <BaseButton
                            full-width
                            variant="quiet"
                            aria-keyshortcuts="3"
                            :disabled="currentPhoto === null || isSubmittingReview || hasImageError"
                            @click="submitReview('rejected')"
                        >
                            <span class="lg:hidden">不要</span>
                            <span class="hidden lg:inline">不要 · 不参与抽奖</span>
                            <kbd class="hidden rounded-md bg-white/70 px-2 py-0.5 font-sans text-xs text-ink-700 lg:inline">0</kbd>
                        </BaseButton>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-rose-100 pt-3 lg:pt-5">
                    <BaseButton
                        full-width
                        variant="secondary"
                        aria-keyshortcuts="U"
                        :disabled="isSubmittingReview || reviewQueueStatus === 'loading'"
                        @click="undoLatestReview"
                    >
                        <span>撤销最近一次审核</span>
                        <kbd class="rounded-md bg-rose-50 px-2 py-0.5 font-sans text-xs text-rose-700">U</kbd>
                    </BaseButton>
                    <p v-if="actionMessage" class="text-center text-sm text-sage-600" role="status">{{ actionMessage }}</p>
                    <p v-if="actionError" class="text-center text-sm text-rose-700" role="alert">{{ actionError }}</p>
                </div>
            </aside>
        </section>
    </DesktopPageShell>
</template>
