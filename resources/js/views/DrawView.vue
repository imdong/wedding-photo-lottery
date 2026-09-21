<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import PhotoDysonCloud from '../components/PhotoDysonCloud.vue';
import {
    executeDrawRound,
    fetchDrawHistory,
    fetchDrawPhotos,
    flattenCandidatePhotos,
    preloadCandidateDrawImages,
    prepareDrawRound,
    type DrawCandidatePhoto,
    type DrawDisplayPhoto,
    type DrawResult,
    type DrawImagePreloadProgress,
    type PreparedDrawRound,
} from '../services/drawRounds';

interface ApiErrorResponse {
    message?: string;
}

type PreparationStatus = 'idle' | 'preparing' | 'prepared' | 'error';
type DrawRequestStatus = 'idle' | 'requesting' | 'ready' | 'error';
type PhotoCloudAnimationStatus = 'idle' | 'running' | 'complete';
type PhotoDisplayStatus = 'loading' | 'ready' | 'error';

const pendingDrawRoundStorageKey = 'wedding.pending-draw-round-id';
const candidatePhotos = ref<DrawCandidatePhoto[]>([]);
const displayPhotos = ref<DrawDisplayPhoto[]>([]);
const photoDisplayStatus = ref<PhotoDisplayStatus>('loading');
const photoDisplayError = ref('');
const preparedRound = ref<PreparedDrawRound | null>(null);
const preparationStatus = ref<PreparationStatus>('idle');
const preparationError = ref('');
const drawResult = ref<DrawResult | null>(null);
const drawHistory = ref<DrawResult[]>([]);
const drawRequestStatus = ref<DrawRequestStatus>('idle');
const drawError = ref('');
const drawAnimationActive = ref(false);
const photoCloudAnimationStatus = ref<PhotoCloudAnimationStatus>('idle');
const activeWinnerIndex = ref<number | null>(null);
const failedDrawImageIds = ref(new Set<number>());
const preloadProgress = ref<DrawImagePreloadProgress>({
    completed: 0,
    failed: 0,
    loaded: 0,
    total: 0,
});
const canStartDraw = computed(() => (
    preparedRound.value !== null
    && drawRequestStatus.value !== 'requesting'
    && photoCloudAnimationStatus.value !== 'running'
    && preloadProgress.value.total > 0
    && preloadProgress.value.completed === preloadProgress.value.total
));
const activeWinner = computed(() => {
    if (activeWinnerIndex.value === null) {
        return null;
    }

    return drawResult.value?.winners[activeWinnerIndex.value] ?? null;
});
const drawButtonLabel = computed(() => {
    if (drawRequestStatus.value === 'requesting') {
        return '正在确定…';
    }

    if (photoCloudAnimationStatus.value === 'running') {
        return '抽奖进行中…';
    }

    if (
        preparationStatus.value === 'preparing'
        || (preparedRound.value !== null && preloadProgress.value.completed < preloadProgress.value.total)
    ) {
        return '正在准备…';
    }

    if (drawResult.value !== null && photoCloudAnimationStatus.value === 'complete') {
        return '开始下一轮抽奖';
    }

    if (drawRequestStatus.value === 'error') {
        return '重试抽奖';
    }

    return '开始抽奖';
});

onMounted(() => {
    void loadDisplayPhotos();
    void refreshDrawHistory(true);
    void prepareRound();
});

watch(candidatePhotos, async (photos) => {
    failedDrawImageIds.value = new Set();

    const result = await preloadCandidateDrawImages(photos, (progress) => {
        preloadProgress.value = progress;
    });

    failedDrawImageIds.value = new Set(result.failedPhotoIds);
});

function handleWinnerFocusStart(): void {
    photoCloudAnimationStatus.value = 'running';
}

function handleWinnerFocusComplete(): void {
    photoCloudAnimationStatus.value = 'complete';
    drawAnimationActive.value = false;
    void refreshDrawHistory();
    void prepareNextRound();
}

function markDrawImageAsFailed(photoId: number): void {
    failedDrawImageIds.value.add(photoId);
}

async function loadDisplayPhotos(): Promise<void> {
    photoDisplayStatus.value = 'loading';
    photoDisplayError.value = '';

    try {
        displayPhotos.value = await fetchDrawPhotos();
        photoDisplayStatus.value = 'ready';
    } catch {
        photoDisplayError.value = '照片云加载失败，请刷新页面重试。';
        photoDisplayStatus.value = 'error';
    }
}

async function refreshDrawHistory(restorePendingResult = false): Promise<void> {
    try {
        drawHistory.value = await fetchDrawHistory();

        if (restorePendingResult) {
            const pendingRoundId = storedPendingDrawRoundId();
            const recoveredResult = pendingRoundId === null
                ? null
                : drawHistory.value.find((round) => round.round_id === pendingRoundId) ?? null;

            if (recoveredResult !== null) {
                drawResult.value = recoveredResult;
                activeWinnerIndex.value = recoveredResult.winners.length > 0 ? 0 : null;
                drawRequestStatus.value = 'ready';
                photoCloudAnimationStatus.value = 'complete';
                forgetPendingDrawRound();
                void prepareNextRound();
            }
        }
    } catch {}
}

async function recoverSavedDrawResult(roundId: number): Promise<DrawResult | null> {
    try {
        const rounds = await fetchDrawHistory();

        drawHistory.value = rounds;
        return rounds.find((round) => round.round_id === roundId) ?? null;
    } catch {
        return null;
    }
}

async function prepareNextRound(): Promise<void> {
    preparedRound.value = null;
    candidatePhotos.value = [];
    preparationStatus.value = 'idle';
    preparationError.value = '';
    preloadProgress.value = {
        completed: 0,
        failed: 0,
        loaded: 0,
        total: 0,
    };

    await prepareRound();
}

async function prepareRound(): Promise<void> {
    if (preparationStatus.value === 'preparing' || preparedRound.value !== null) {
        return;
    }

    preparationStatus.value = 'preparing';
    preparationError.value = '';

    try {
        const round = await prepareDrawRound();

        preparedRound.value = round;
        candidatePhotos.value = flattenCandidatePhotos(round);
        preparationStatus.value = 'prepared';

        if (storedPendingDrawRoundId() === round.id && drawResult.value === null) {
            drawRequestStatus.value = 'error';
            drawError.value = '上次抽奖结果尚未确认，请重试获取。';
        }
    } catch (error: unknown) {
        preparationError.value = isAxiosError<ApiErrorResponse>(error)
            ? error.response?.data.message ?? '准备失败，请稍后重试。'
            : '准备失败，请稍后重试。';
        preparationStatus.value = 'error';
    }
}

async function startDraw(): Promise<void> {
    if (! canStartDraw.value || preparedRound.value === null) {
        return;
    }

    drawRequestStatus.value = 'requesting';
    drawError.value = '';
    drawAnimationActive.value = true;
    photoCloudAnimationStatus.value = 'idle';
    drawResult.value = null;
    activeWinnerIndex.value = null;
    rememberPendingDrawRound(preparedRound.value.id);

    try {
        drawResult.value = await executeDrawRound(preparedRound.value.id);
        forgetPendingDrawRound();
        drawRequestStatus.value = 'ready';
        activeWinnerIndex.value = drawResult.value.winners.length > 0 ? 0 : null;

        if (activeWinnerIndex.value === null) {
            drawAnimationActive.value = false;
            photoCloudAnimationStatus.value = 'complete';
        }
    } catch (error: unknown) {
        const recoveredResult = await recoverSavedDrawResult(preparedRound.value.id);

        if (recoveredResult !== null) {
            forgetPendingDrawRound();
            drawResult.value = recoveredResult;
            drawRequestStatus.value = 'ready';
            drawError.value = '';
            activeWinnerIndex.value = recoveredResult.winners.length > 0 ? 0 : null;

            if (activeWinnerIndex.value === null) {
                drawAnimationActive.value = false;
                photoCloudAnimationStatus.value = 'complete';
            }

            return;
        }

        drawError.value = isAxiosError<ApiErrorResponse>(error)
            ? error.response?.data.message ?? '抽奖失败，请稍后重试。'
            : '抽奖失败，请稍后重试。';
        drawRequestStatus.value = 'error';
        drawAnimationActive.value = false;
    }
}

function rememberPendingDrawRound(roundId: number): void {
    window.localStorage.setItem(pendingDrawRoundStorageKey, String(roundId));
}

function forgetPendingDrawRound(): void {
    window.localStorage.removeItem(pendingDrawRoundStorageKey);
}

function storedPendingDrawRoundId(): number | null {
    const storedRoundId = Number(window.localStorage.getItem(pendingDrawRoundStorageKey));

    return Number.isInteger(storedRoundId) && storedRoundId > 0 ? storedRoundId : null;
}
</script>

<template>
    <div class="relative h-dvh min-h-[640px] overflow-hidden bg-[#21191c] text-white">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_-10%,rgb(190_120_131_/_0.3),transparent_42%),radial-gradient(circle_at_8%_88%,rgb(185_140_76_/_0.2),transparent_34%)]"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.035] [background-image:linear-gradient(rgb(255_255_255)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255)_1px,transparent_1px)] [background-size:4rem_4rem]"></div>

        <main class="absolute inset-0" aria-label="全部审核通过照片展示区域">
            <div class="pointer-events-none absolute inset-[5%] rounded-[50%] bg-rose-500/10 blur-3xl"></div>

            <PhotoDysonCloud
                :drawing="drawAnimationActive"
                :failed-draw-image-ids="failedDrawImageIds"
                :photos="displayPhotos"
                :winner="activeWinner"
                @draw-image-error="markDrawImageAsFailed"
                @winner-focus-complete="handleWinnerFocusComplete"
                @winner-focus-start="handleWinnerFocusStart"
            />

            <div class="absolute top-6 right-7 z-[120] flex items-center gap-3 rounded-pill border border-white/12 bg-black/25 px-4 py-2.5 text-sm text-white/70 shadow-lg backdrop-blur-xl 2xl:top-9 2xl:right-10">
                <span class="size-2 rounded-full bg-gold-300 shadow-[0_0_0_5px_rgb(223_194_143_/_0.12)]"></span>
                <span><strong class="font-semibold text-white">{{ displayPhotos.length }}</strong> 张照片</span>
            </div>

            <div v-if="displayPhotos.length === 0" class="absolute inset-0 z-20 grid place-items-center">
                <div class="flex max-w-sm flex-col items-center gap-4 text-center">
                    <div class="grid size-20 place-items-center rounded-full border border-gold-300/35 bg-[#2a2023]/85 shadow-[0_18px_60px_rgb(0_0_0_/_0.35)] backdrop-blur-xl 2xl:size-24">
                        <span class="font-display text-4xl text-gold-100 2xl:text-5xl">囍</span>
                    </div>
                    <div>
                        <h1 class="font-display text-xl font-semibold tracking-wider text-white 2xl:text-2xl">照片云正在形成</h1>
                        <p class="mt-2 text-sm text-white/45">{{ photoDisplayStatus === 'error' ? photoDisplayError : '正在载入照片' }}</p>
                    </div>
                </div>
            </div>

            <div
                v-if="preparationError !== '' || drawError !== ''"
                class="absolute bottom-24 left-1/2 z-[120] -translate-x-1/2 rounded-pill border border-rose-300/20 bg-black/45 px-5 py-2.5 text-sm text-rose-200 shadow-lg backdrop-blur-xl"
            >
                {{ drawError || preparationError }}
            </div>

            <div class="absolute right-7 bottom-7 z-[120] 2xl:right-10 2xl:bottom-10">
                <BaseButton
                    class="min-w-48 shadow-[0_14px_45px_rgb(0_0_0_/_0.35)] 2xl:min-w-56"
                    :disabled="!canStartDraw"
                    @click="startDraw"
                >
                    {{ drawButtonLabel }}
                </BaseButton>
            </div>
        </main>
    </div>
</template>
