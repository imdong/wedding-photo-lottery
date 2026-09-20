<script setup lang="ts">
import { isAxiosError } from 'axios';
import { onMounted, ref } from 'vue';
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
const remainingCount = ref(0);
const isLoggingIn = ref(false);
const isSubmittingReview = ref(false);
const hasImageError = ref(false);

onMounted(() => {
    void restoreAdminSession();
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

async function loadNextReview(): Promise<void> {
    if (adminToken.value === '') {
        return;
    }

    reviewQueueStatus.value = 'loading';
    currentPhoto.value = null;
    actionError.value = '';
    hasImageError.value = false;

    try {
        const queue = await fetchNextAdminReview(adminToken.value);

        currentPhoto.value = queue.photo;
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
        await loadNextReview();
    } catch (error: unknown) {
        if (isUnauthorized(error)) {
            expireAdminSession();

            return;
        }

        if (isAxiosError<ApiErrorResponse>(error) && error.response?.status === 409) {
            actionMessage.value = '照片状态已更新，已为你刷新队列。';
            await loadNextReview();

            return;
        }

        actionError.value = getApiErrorMessage(error, '审核失败，请稍后重试。');
    } finally {
        isSubmittingReview.value = false;
    }
}

async function undoLatestReview(): Promise<void> {
    if (isSubmittingReview.value || adminToken.value === '') {
        return;
    }

    isSubmittingReview.value = true;
    actionError.value = '';
    actionMessage.value = '';

    try {
        await undoLatestAdminReview(adminToken.value);
        actionMessage.value = '已撤销最近一次审核。';
        await loadNextReview();
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

function expireAdminSession(): void {
    clearAdminSession();
    loginError.value = '登录已过期，请重新登录。';
}

function clearAdminSession(): void {
    localStorage.removeItem(adminTokenStorageKey);
    adminToken.value = '';
    currentPhoto.value = null;
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

        <section v-else class="grid gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(20rem,0.55fr)]">
            <div class="relative grid min-h-[32rem] overflow-hidden rounded-card border border-white/85 bg-white/68 shadow-card backdrop-blur-sm lg:min-h-[42rem]">
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

                    <div class="absolute inset-x-0 bottom-0 flex flex-wrap items-end justify-between gap-3 bg-linear-to-t from-ink-950/80 via-ink-950/45 to-transparent px-6 pt-20 pb-5 text-white">
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

            <aside class="flex flex-col gap-5 rounded-card border border-white/85 bg-paper/85 p-6 shadow-card backdrop-blur-sm">
                <div class="flex items-center justify-between gap-4 border-b border-rose-100 pb-5">
                    <div class="flex flex-col gap-1">
                        <p class="text-xs font-semibold tracking-[0.2em] text-rose-600 uppercase">待处理</p>
                        <p class="font-display text-4xl font-semibold text-ink-950">{{ remainingCount }}</p>
                    </div>
                    <span :class="['rounded-pill px-3 py-1.5 text-xs font-semibold', remainingCount === 0 ? 'bg-sage-100 text-sage-600' : 'bg-gold-100 text-gold-500']">
                        {{ remainingCount === 0 ? '队列已清空' : '张照片' }}
                    </span>
                </div>

                <div class="flex flex-col gap-3">
                    <p class="text-sm font-semibold text-ink-700">审核操作</p>
                    <BaseButton full-width :disabled="currentPhoto === null || isSubmittingReview || hasImageError" @click="submitReview('good')">
                        好 · 2 倍权重
                    </BaseButton>
                    <BaseButton full-width variant="secondary" :disabled="currentPhoto === null || isSubmittingReview || hasImageError" @click="submitReview('normal')">
                        普通 · 1 倍权重
                    </BaseButton>
                    <BaseButton full-width variant="quiet" :disabled="currentPhoto === null || isSubmittingReview || hasImageError" @click="submitReview('rejected')">
                        不要 · 不参与抽奖
                    </BaseButton>
                </div>

                <div class="flex flex-col gap-3 border-t border-rose-100 pt-5">
                    <BaseButton full-width variant="secondary" :disabled="isSubmittingReview" @click="undoLatestReview">
                        撤销最近一次审核
                    </BaseButton>
                    <p v-if="actionMessage" class="text-center text-sm text-sage-600" role="status">{{ actionMessage }}</p>
                    <p v-if="actionError" class="text-center text-sm text-rose-700" role="alert">{{ actionError }}</p>
                </div>
            </aside>
        </section>
    </DesktopPageShell>
</template>
