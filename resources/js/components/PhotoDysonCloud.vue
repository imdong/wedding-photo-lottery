<script setup lang="ts">
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type CSSProperties,
} from 'vue';
import {
    preloadDrawImage,
    type DrawDisplayPhoto,
    type DrawWinner,
} from '../services/drawRounds';

type DrawPhase = 'ambient' | 'spinning' | 'slowing' | 'stopped';

interface CloudParticle {
    driftDelay: number;
    driftDuration: number;
    driftX: number;
    driftY: number;
    key: string;
    latitude: number;
    longitude: number;
    opacity: number;
    photo: DrawDisplayPhoto;
    radialOffset: number;
    size: number;
}

interface FocusedPhoto {
    aspectRatio: number;
    expanded: boolean;
    imageUrl: string;
    particleKey: string;
    photoId: number;
    source: SourceRectangle;
}

interface PendingWinner {
    imageUrl: string;
    particle: CloudParticle;
}

interface SourceRectangle {
    height: number;
    left: number;
    top: number;
    width: number;
}

interface NavigatorWithDeviceMemory extends Navigator {
    deviceMemory?: number;
}

const props = defineProps<{
    drawing: boolean;
    failedDrawImageIds: Set<number>;
    photos: DrawDisplayPhoto[];
    winner: DrawWinner | null;
}>();

const emit = defineEmits<{
    drawImageError: [photoId: number];
    winnerFocusComplete: [];
    winnerFocusStart: [];
}>();

const ambientRotationSpeed = -1.25;
const focusedPhotoViewportCoverage = 0.88;

// 球体进入抽奖后的最高转速，绝对值越大旋转越快。
const rapidRotationSpeed = -240;

// 球体从环境转速加速到最高转速的速度，数值越大越快达到高速。
const rapidSpinAcceleration = 12;

// 首次抽奖至少保持高速旋转的时间；中奖结果提前返回时也会等待到这个时间。
const initialRapidSpinDurationMilliseconds = 700;

// 连续揭晓下一张照片时至少保持高速旋转的时间。
const subsequentRapidSpinDurationMilliseconds = 400;

// 球体从最高转速逐渐停下来的最短和最长时间。
const sphereSlowdownMinimumDurationMilliseconds = 12_000;
const sphereSlowdownMaximumDurationMilliseconds = 15_500;

// 高亮快速切换时每张照片停留的时间，数值越小切换越快。
const rapidHighlightIntervalMilliseconds = 60;

// 减速曲线强度，数值越大越偏向前快后慢。
const slowdownCurvePower = 7;

// 球体减速期间至少继续旋转的圈数，避免过早停住。
const slowdownMinimumTurns = 4;

// 高亮接近最终结果时，单张照片最长可停留的时间。
const slowdownMaximumHighlightIntervalMilliseconds = 1_500;

// 高亮刹车力度：数值越大，越早从快速切换过渡到慢速切换。
const highlightSlowdownCurvePower = 4;
const stage = ref<HTMLElement | null>(null);
const sphereRadius = ref(285);
const sphereRotation = ref(0);
const renderKey = ref(0);
const particles = ref<CloudParticle[]>([]);
const focusedPhoto = ref<FocusedPhoto | null>(null);
const highlightedParticleKey = ref<string | null>(null);
const failedThumbnailIds = ref(new Set<number>());
const particleElements = new Map<string, HTMLElement>();
const scheduledTimeouts = new Set<number>();
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const deviceMemory = (navigator as NavigatorWithDeviceMemory).deviceMemory ?? 8;
const isLowPerformanceDevice = navigator.hardwareConcurrency <= 4 || deviceMemory <= 4;
const particleCount = isLowPerformanceDevice ? 440 : 680;
let resizeObserver: ResizeObserver | null = null;
let animationFrameId: number | null = null;
let photoReplacementTimerId: number | null = null;
let lastFrameAt = performance.now();
let rotationVelocity = ambientRotationSpeed;
let drawPhase: DrawPhase = 'ambient';
let focusSequence = 0;
let lastAmbientPhotoId: number | null = null;
let nextHighlightAt = 0;
let rapidSpinStartedAt = 0;
let slowdownStartedAt = 0;
let slowdownStartRotation = 0;
let slowdownTargetRotation = 0;
let slowdownDurationMilliseconds = sphereSlowdownMinimumDurationMilliseconds;
let pendingWinner: PendingWinner | null = null;

watch(
    () => props.photos,
    async (photos) => {
        cancelFocusSequence();
        failedThumbnailIds.value = new Set();
        particleElements.clear();
        particles.value = buildParticles(photos);
        renderKey.value += 1;
        lastAmbientPhotoId = null;

        await nextTick();

        if (drawPhase === 'ambient') {
            scheduleNextAmbientFocus(1_100);
        }
    },
    { immediate: true },
);

watch(
    () => props.drawing,
    (drawing) => {
        if (drawing && props.winner === null) {
            startRapidSpin();

            return;
        }

        if (! drawing && props.winner === null && drawPhase === 'spinning') {
            returnToAmbientSpin();
        }
    },
);

watch(
    () => props.winner,
    async (winner) => {
        if (winner === null) {
            return;
        }

        const isSubsequentWinner = drawPhase === 'stopped';

        cancelFocusSequence();

        if (isSubsequentWinner) {
            startRapidSpin();
        }

        const particle = await ensureWinnerParticle(winner.photo.id);

        emit('winnerFocusStart');

        if (particle === null) {
            emit('winnerFocusComplete');

            return;
        }

        const sequence = focusSequence;
        const rapidSpinElapsed = performance.now() - rapidSpinStartedAt;
        const minimumRapidSpin = isSubsequentWinner
            ? subsequentRapidSpinDurationMilliseconds
            : initialRapidSpinDurationMilliseconds;
        const remainingRapidSpin = rapidSpinStartedAt === 0
            ? 0
            : Math.max(0, minimumRapidSpin - rapidSpinElapsed);

        if (remainingRapidSpin > 0) {
            await wait(remainingRapidSpin);
        }

        if (sequence !== focusSequence) {
            return;
        }

        beginSlowdown({
            imageUrl: winner.photo.draw_url,
            particle,
        });
    },
);

onMounted(() => {
    resizeObserver = new ResizeObserver(updateSphereSize);

    if (stage.value !== null) {
        resizeObserver.observe(stage.value);
    }

    updateSphereSize();
    scheduleNextPhotoReplacement();
    lastFrameAt = performance.now();
    animationFrameId = window.requestAnimationFrame(animateSphere);
});

onBeforeUnmount(() => {
    cancelFocusSequence();
    clearPhotoReplacementTimer();
    resizeObserver?.disconnect();

    if (animationFrameId !== null) {
        window.cancelAnimationFrame(animationFrameId);
    }
});

function buildParticles(photos: DrawDisplayPhoto[]): CloudParticle[] {
    if (photos.length === 0) {
        return [];
    }

    const shuffledPhotos = shufflePhotos(photos);

    return Array.from({ length: particleCount }, (_, index): CloudParticle => {
        const photo = shuffledPhotos[index % shuffledPhotos.length];
        const latitude = Math.asin(Math.random() * 2 - 1) * (180 / Math.PI);

        return {
            driftDelay: Math.random() * -18,
            driftDuration: 12 + Math.random() * 16,
            driftX: 1.5 + Math.random() * 4,
            driftY: 1.5 + Math.random() * 4,
            key: `${photo.photo_id}-${index}`,
            latitude,
            longitude: Math.random() * 360,
            opacity: 0.68 + Math.random() * 0.32,
            photo,
            radialOffset: Math.random() * 20 - 10,
            size: 27 + Math.random() * 10,
        };
    });
}

function shufflePhotos(photos: DrawDisplayPhoto[]): DrawDisplayPhoto[] {
    const shuffledPhotos = [...photos];

    for (let index = shuffledPhotos.length - 1; index > 0; index -= 1) {
        const replacementIndex = Math.floor(Math.random() * (index + 1));
        [shuffledPhotos[index], shuffledPhotos[replacementIndex]] = [
            shuffledPhotos[replacementIndex],
            shuffledPhotos[index],
        ];
    }

    return shuffledPhotos;
}

function scheduleNextPhotoReplacement(): void {
    clearPhotoReplacementTimer();
    photoReplacementTimerId = window.setTimeout(() => {
        replaceRandomPhoto();
        scheduleNextPhotoReplacement();
    }, 1_800 + Math.random() * 2_200);
}

function clearPhotoReplacementTimer(): void {
    if (photoReplacementTimerId !== null) {
        window.clearTimeout(photoReplacementTimerId);
        photoReplacementTimerId = null;
    }
}

function replaceRandomPhoto(): void {
    if (
        props.drawing
        || drawPhase !== 'ambient'
        || focusedPhoto.value !== null
        || props.photos.length < 2
    ) {
        return;
    }

    const replaceableParticles = particles.value.filter(
        (particle) => particle.key !== highlightedParticleKey.value,
    );
    const particle = replaceableParticles[Math.floor(Math.random() * replaceableParticles.length)];

    if (particle === undefined) {
        return;
    }

    const displayedPhotoIds = new Set(
        particles.value.map((displayedParticle) => displayedParticle.photo.photo_id),
    );
    const unseenPhotos = props.photos.filter(
        (photo) => ! displayedPhotoIds.has(photo.photo_id),
    );
    const replacementPhotos = unseenPhotos.length > 0
        ? unseenPhotos
        : props.photos.filter((photo) => photo.photo_id !== particle.photo.photo_id);
    const replacementPhoto = replacementPhotos[
        Math.floor(Math.random() * replacementPhotos.length)
    ];

    if (replacementPhoto === undefined) {
        return;
    }

    const currentPhotoId = particle.photo.photo_id;
    const image = new Image();

    image.onload = () => {
        if (
            ! props.drawing
            && drawPhase === 'ambient'
            && particle.photo.photo_id === currentPhotoId
            && particles.value.includes(particle)
        ) {
            particle.photo = replacementPhoto;
        }
    };
    image.src = replacementPhoto.thumbnail_url;
}

async function ensureWinnerParticle(photoId: number): Promise<CloudParticle | null> {
    const existingParticle = winnerParticle(photoId);

    if (existingParticle !== null) {
        return existingParticle;
    }

    const winnerPhoto = props.photos.find((photo) => photo.photo_id === photoId);
    const particle = particlesNearFront()
        .sort((first, second) => Math.abs(first.latitude) - Math.abs(second.latitude))[0]
        ?? particles.value[0];

    if (winnerPhoto === undefined || particle === undefined) {
        return null;
    }

    particle.photo = winnerPhoto;
    await nextTick();

    return particle;
}

function animateSphere(now: number): void {
    const elapsedSeconds = Math.min((now - lastFrameAt) / 1_000, 0.08);
    lastFrameAt = now;

    if (drawPhase === 'slowing') {
        updateSlowdown(now);
    } else if (drawPhase !== 'stopped') {
        const targetVelocity = drawPhase === 'spinning'
            ? rapidRotationSpeed
            : ambientRotationSpeed;
        const acceleration = drawPhase === 'spinning' ? rapidSpinAcceleration : 1.2;
        const interpolation = Math.min(1, elapsedSeconds * acceleration);

        rotationVelocity += (targetVelocity - rotationVelocity) * interpolation;
        sphereRotation.value += rotationVelocity * elapsedSeconds;

        if (drawPhase === 'spinning' && now >= nextHighlightAt) {
            highlightRandomFrontParticle();
            nextHighlightAt = now + rapidHighlightIntervalMilliseconds;
        }
    }

    animationFrameId = window.requestAnimationFrame(animateSphere);
}

function updateSlowdown(now: number): void {
    const progress = Math.min(
        1,
        (now - slowdownStartedAt) / slowdownDurationMilliseconds,
    );
    const easedProgress = 1 - (1 - progress) ** slowdownCurvePower;

    sphereRotation.value = slowdownStartRotation
        + (slowdownTargetRotation - slowdownStartRotation) * easedProgress;

    if (now >= nextHighlightAt && progress < 1) {
        const brakingProgress = 1 - (1 - progress) ** highlightSlowdownCurvePower;
        const highlightInterval = rapidHighlightIntervalMilliseconds
            + brakingProgress * slowdownMaximumHighlightIntervalMilliseconds;

        highlightRandomFrontParticle();
        nextHighlightAt = now + highlightInterval;
    }

    if (progress >= 1) {
        finishSlowdown();
    }
}

function startRapidSpin(): void {
    cancelFocusSequence();
    pendingWinner = null;
    drawPhase = 'spinning';
    rapidSpinStartedAt = performance.now();
    highlightRandomFrontParticle();
    nextHighlightAt = rapidSpinStartedAt + rapidHighlightIntervalMilliseconds;
}

function returnToAmbientSpin(): void {
    drawPhase = 'ambient';
    rapidSpinStartedAt = 0;
    highlightedParticleKey.value = null;
    scheduleNextAmbientFocus(1_200);
}

function beginSlowdown(winner: PendingWinner): void {
    pendingWinner = winner;
    drawPhase = 'slowing';
    rapidSpinStartedAt = 0;
    slowdownStartedAt = performance.now();
    slowdownStartRotation = sphereRotation.value;
    slowdownTargetRotation = targetRotationFor(winner.particle);

    const angularDistance = Math.abs(slowdownTargetRotation - slowdownStartRotation);
    slowdownDurationMilliseconds = Math.min(
        sphereSlowdownMaximumDurationMilliseconds,
        Math.max(
            sphereSlowdownMinimumDurationMilliseconds,
            angularDistance * slowdownCurvePower / Math.abs(rapidRotationSpeed) * 1_000,
        ),
    );
    nextHighlightAt = slowdownStartedAt;
}

function finishSlowdown(): void {
    if (drawPhase !== 'slowing' || pendingWinner === null) {
        return;
    }

    const winner = pendingWinner;

    drawPhase = 'stopped';
    rotationVelocity = 0;
    sphereRotation.value = slowdownTargetRotation;
    highlightedParticleKey.value = winner.particle.key;

    scheduleTimeout(() => {
        void focusParticle(winner.particle, winner.imageUrl, true);
    }, 1_000);
}

function targetRotationFor(particle: CloudParticle): number {
    let targetRotation = -particle.longitude;
    const minimumAngularDistance = 360 * slowdownMinimumTurns;

    while (targetRotation > sphereRotation.value - minimumAngularDistance) {
        targetRotation -= 360;
    }

    return targetRotation;
}

function particleStyle(particle: CloudParticle): CSSProperties {
    return {
        '--drift-delay': `${particle.driftDelay}s`,
        '--drift-duration': `${particle.driftDuration}s`,
        '--drift-x': `${particle.driftX}px`,
        '--drift-y': `${particle.driftY}px`,
        height: `${particle.size}px`,
        marginLeft: `${particle.size / -2}px`,
        marginTop: `${particle.size / -2}px`,
        opacity: particle.opacity,
        transform: `rotateY(${particle.longitude}deg) rotateX(${particle.latitude}deg) translateZ(${sphereRadius.value + particle.radialOffset}px)`,
        width: `${particle.size}px`,
    } as CSSProperties;
}

function sphereStyle(): CSSProperties {
    return {
        transform: `rotateX(-7deg) rotateY(${sphereRotation.value}deg)`,
    };
}

function guideStyle(): CSSProperties {
    const diameter = sphereRadius.value * 2;

    return {
        height: `${diameter}px`,
        marginLeft: `${diameter / -2}px`,
        marginTop: `${diameter / -2}px`,
        width: `${diameter}px`,
    };
}

function focusStyle(): CSSProperties {
    const focus = focusedPhoto.value;

    if (focus === null) {
        return {};
    }

    if (focus.expanded) {
        const stageElement = stage.value;

        if (stageElement === null) {
            return {};
        }

        const maximumWidth = stageElement.clientWidth * focusedPhotoViewportCoverage;
        const maximumHeight = stageElement.clientHeight * focusedPhotoViewportCoverage;
        const widthConstrainedHeight = maximumWidth / focus.aspectRatio;
        const width = widthConstrainedHeight <= maximumHeight
            ? maximumWidth
            : maximumHeight * focus.aspectRatio;
        const height = widthConstrainedHeight <= maximumHeight
            ? widthConstrainedHeight
            : maximumHeight;

        return {
            height: `${height}px`,
            left: '50%',
            top: '50%',
            transform: 'translate(-50%, -50%)',
            width: `${width}px`,
        };
    }

    return {
        height: `${focus.source.height}px`,
        left: `${focus.source.left}px`,
        top: `${focus.source.top}px`,
        transform: 'translate(0, 0)',
        width: `${focus.source.width}px`,
    };
}

function setParticleElement(particleKey: string, element: unknown): void {
    if (element instanceof HTMLElement) {
        particleElements.set(particleKey, element);

        return;
    }

    particleElements.delete(particleKey);
}

function updateSphereSize(): void {
    const stageElement = stage.value;

    if (stageElement === null) {
        return;
    }

    sphereRadius.value = Math.max(
        220,
        Math.min(480, stageElement.clientWidth * 0.36, stageElement.clientHeight * 0.45),
    );
}

function markThumbnailAsFailed(photoId: number): void {
    failedThumbnailIds.value.add(photoId);
}

function markFocusedImageAsFailed(photoId: number): void {
    emit('drawImageError', photoId);
}

function scheduleNextAmbientFocus(delayMilliseconds: number): void {
    if (
        particles.value.length === 0
        || props.winner !== null
        || props.drawing
        || prefersReducedMotion
        || drawPhase !== 'ambient'
    ) {
        return;
    }

    scheduleTimeout(() => {
        void focusRandomFrontPhoto();
    }, delayMilliseconds);
}

async function focusRandomFrontPhoto(): Promise<void> {
    if (props.drawing || props.winner !== null || focusedPhoto.value !== null) {
        return;
    }

    const availableParticles = particlesNearFront().filter(
        (particle) => ! props.failedDrawImageIds.has(particle.photo.photo_id),
    );
    const selectableParticles = availableParticles.some(
        (particle) => particle.photo.photo_id !== lastAmbientPhotoId,
    )
        ? availableParticles.filter((particle) => particle.photo.photo_id !== lastAmbientPhotoId)
        : availableParticles;
    const particle = selectableParticles[Math.floor(Math.random() * selectableParticles.length)];

    if (particle === undefined) {
        scheduleNextAmbientFocus(1_200);

        return;
    }

    lastAmbientPhotoId = particle.photo.photo_id;
    await focusParticle(particle, particle.photo.draw_url, false);
}

async function focusParticle(
    particle: CloudParticle,
    imageUrl: string,
    keepExpanded: boolean,
): Promise<void> {
    const sequence = ++focusSequence;
    let aspectRatio = 1;

    try {
        const image = await preloadDrawImage(imageUrl);

        if (image.naturalWidth > 0 && image.naturalHeight > 0) {
            aspectRatio = image.naturalWidth / image.naturalHeight;
        }
    } catch {
        emit('drawImageError', particle.photo.photo_id);
    }

    if (sequence !== focusSequence) {
        return;
    }

    highlightedParticleKey.value = particle.key;
    await wait(prefersReducedMotion ? 80 : 380);

    if (sequence !== focusSequence) {
        return;
    }

    const source = sourceRectangle(particle.key);

    if (source === null) {
        if (keepExpanded) {
            emit('winnerFocusComplete');
        } else {
            highlightedParticleKey.value = null;
            scheduleNextAmbientFocus(1_200);
        }

        return;
    }

    focusedPhoto.value = {
        aspectRatio,
        expanded: false,
        imageUrl,
        particleKey: particle.key,
        photoId: particle.photo.photo_id,
        source,
    };

    await nextTick();
    await nextAnimationFrame();

    if (sequence !== focusSequence || focusedPhoto.value === null) {
        return;
    }

    focusedPhoto.value.expanded = true;
    await wait(prefersReducedMotion ? 180 : 950);

    if (sequence !== focusSequence || focusedPhoto.value === null) {
        return;
    }

    if (keepExpanded) {
        emit('winnerFocusComplete');

        return;
    }

    await wait(isLowPerformanceDevice ? 1_800 : 2_700);

    if (sequence !== focusSequence || focusedPhoto.value === null) {
        return;
    }

    focusedPhoto.value.source = sourceRectangle(particle.key) ?? focusedPhoto.value.source;
    focusedPhoto.value.expanded = false;
    await wait(prefersReducedMotion ? 180 : 900);

    if (sequence !== focusSequence) {
        return;
    }

    focusedPhoto.value = null;
    await wait(prefersReducedMotion ? 80 : 420);

    if (sequence !== focusSequence) {
        return;
    }

    highlightedParticleKey.value = null;
    scheduleNextAmbientFocus(isLowPerformanceDevice ? 1_600 : 1_000);
}

function highlightRandomFrontParticle(): void {
    const visibleParticles = particlesNearFront();
    const selectableParticles = visibleParticles.some(
        (particle) => particle.key !== highlightedParticleKey.value,
    )
        ? visibleParticles.filter((particle) => particle.key !== highlightedParticleKey.value)
        : visibleParticles;
    const particle = selectableParticles[Math.floor(Math.random() * selectableParticles.length)];

    if (particle !== undefined) {
        highlightedParticleKey.value = particle.key;
    }
}

function particlesNearFront(): CloudParticle[] {
    return particles.value.filter((particle) => (
        Math.abs(currentParticleAngle(particle)) <= 70
        && Math.abs(particle.latitude) <= 72
        && particleElements.has(particle.key)
    ));
}

function winnerParticle(photoId: number): CloudParticle | null {
    return particles.value
        .filter((particle) => particle.photo.photo_id === photoId)
        .sort((first, second) => Math.abs(first.latitude) - Math.abs(second.latitude))[0] ?? null;
}

function currentParticleAngle(particle: CloudParticle): number {
    return normalizeAngle(particle.longitude + sphereRotation.value);
}

function sourceRectangle(particleKey: string): SourceRectangle | null {
    const stageElement = stage.value;
    const particleElement = particleElements.get(particleKey);

    if (stageElement === null || particleElement === undefined) {
        return null;
    }

    const stageRectangle = stageElement.getBoundingClientRect();
    const particleRectangle = particleElement.getBoundingClientRect();

    if (particleRectangle.width < 2 || particleRectangle.height < 2) {
        return null;
    }

    return {
        height: particleRectangle.height,
        left: particleRectangle.left - stageRectangle.left,
        top: particleRectangle.top - stageRectangle.top,
        width: particleRectangle.width,
    };
}

function cancelFocusSequence(): void {
    focusSequence += 1;
    focusedPhoto.value = null;
    highlightedParticleKey.value = null;

    scheduledTimeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
    scheduledTimeouts.clear();
}

function scheduleTimeout(callback: () => void, delayMilliseconds: number): void {
    const timeoutId = window.setTimeout(() => {
        scheduledTimeouts.delete(timeoutId);
        callback();
    }, delayMilliseconds);

    scheduledTimeouts.add(timeoutId);
}

function wait(delayMilliseconds: number): Promise<void> {
    return new Promise((resolve) => {
        scheduleTimeout(resolve, delayMilliseconds);
    });
}

function nextAnimationFrame(): Promise<void> {
    return new Promise((resolve) => {
        window.requestAnimationFrame(() => resolve());
    });
}

function normalizeAngle(angle: number): number {
    return ((angle + 180) % 360 + 360) % 360 - 180;
}
</script>

<template>
    <div ref="stage" class="absolute inset-0 overflow-hidden [perspective:1400px]">
        <div
            class="pointer-events-none absolute top-1/2 left-1/2 rounded-full border border-gold-300/10 bg-[radial-gradient(circle_at_34%_30%,rgb(255_255_255_/_0.05),transparent_38%),radial-gradient(circle_at_50%_50%,transparent_45%,rgb(0_0_0_/_0.1)_100%)] shadow-[inset_-35px_-20px_80px_rgb(0_0_0_/_0.12),0_0_110px_rgb(185_140_76_/_0.08)]"
            :style="guideStyle()"
        ></div>

        <div
            :key="renderKey"
            class="absolute inset-0 [transform-style:preserve-3d] will-change-transform"
            :style="sphereStyle()"
        >
            <div
                v-for="(particle, index) in particles"
                :key="particle.key"
                :ref="(element) => setParticleElement(particle.key, element)"
                class="absolute top-1/2 left-1/2 [transform-style:preserve-3d]"
                :class="{
                    'z-50': highlightedParticleKey === particle.key,
                    'opacity-0': focusedPhoto?.particleKey === particle.key,
                }"
                :style="particleStyle(particle)"
            >
                <div
                    class="sphere-photo-drift h-full w-full"
                    :class="{ 'sphere-photo-drift--still': isLowPerformanceDevice && index % 2 === 1 }"
                >
                    <div
                        class="sphere-photo-glow h-full w-full overflow-hidden rounded-md border border-white/35 bg-gold-100 shadow-[0_5px_16px_rgb(0_0_0_/_0.34)] [backface-visibility:hidden]"
                        :class="{
                            'sphere-photo-glow--drawing': drawing,
                            'sphere-photo-glow--highlighted': highlightedParticleKey === particle.key,
                        }"
                    >
                        <img
                            v-if="! failedThumbnailIds.has(particle.photo.photo_id)"
                            :src="particle.photo.thumbnail_url"
                            alt=""
                            class="h-full w-full object-cover"
                            decoding="async"
                            loading="eager"
                            @error="markThumbnailAsFailed(particle.photo.photo_id)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="focusedPhoto !== null"
            class="focused-photo absolute z-[100] overflow-hidden rounded-2xl border-2 border-gold-300 bg-[#2b2024] p-1.5 shadow-[0_28px_100px_rgb(0_0_0_/_0.7)] transition-[top,left,width,height,transform,opacity] duration-900 ease-[cubic-bezier(0.16,1,0.3,1)]"
            :style="focusStyle()"
        >
            <img
                v-if="! failedDrawImageIds.has(focusedPhoto.photoId)"
                :src="focusedPhoto.imageUrl"
                alt="正在展示的照片"
                class="h-full w-full rounded-xl bg-black/20 object-contain"
                @error="markFocusedImageAsFailed(focusedPhoto.photoId)"
            />
            <div v-else class="grid h-full w-full place-items-center rounded-xl bg-linear-to-br from-rose-100/70 to-gold-100/60 font-display text-5xl text-rose-700">囍</div>

            <div
                v-if="winner !== null"
                class="pointer-events-none absolute inset-x-4 bottom-4 flex items-end justify-between gap-4 rounded-xl border border-white/20 bg-black/55 px-4 py-3 text-white shadow-lg backdrop-blur-md"
            >
                <div>
                    <p class="text-[0.65rem] font-semibold tracking-[0.2em] text-gold-200 uppercase">幸运照片</p>
                    <p class="mt-1 font-display text-xl font-semibold tracking-wider">手机号尾号 {{ winner.phone_suffix }}</p>
                </div>
                <span class="shrink-0 font-display text-3xl text-gold-200">囍</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sphere-photo-drift {
    animation: sphere-photo-drift var(--drift-duration) ease-in-out var(--drift-delay) infinite alternate;
}

.sphere-photo-drift--still {
    animation: none;
}

.sphere-photo-glow {
    transition: filter 180ms ease, box-shadow 180ms ease, transform 180ms ease;
}

.sphere-photo-glow--drawing {
    transition-duration: 20ms;
}

.sphere-photo-glow--highlighted {
    filter: brightness(1.85) saturate(1.2);
    box-shadow: 0 0 0 2px rgb(255 239 190 / 0.9), 0 0 24px rgb(255 213 121 / 0.95);
    transform: scale(1.32);
}

.focused-photo {
    transform-origin: center;
}

@keyframes sphere-photo-drift {
    from {
        transform: translate3d(calc(var(--drift-x) * -1), calc(var(--drift-y) * -1), 0) rotate(-1.4deg);
    }

    to {
        transform: translate3d(var(--drift-x), var(--drift-y), 0) rotate(1.4deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .sphere-photo-drift {
        animation-duration: 90s;
    }
}
</style>
