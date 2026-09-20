<script setup lang="ts">
defineProps<{
    sectionLabel: string;
}>();
</script>

<template>
    <div class="relative min-h-dvh overflow-hidden bg-canvas">
        <div class="pointer-events-none absolute -top-56 left-1/2 size-[44rem] -translate-x-1/2 rounded-full bg-rose-100/70 blur-3xl"></div>
        <div class="pointer-events-none absolute -right-40 bottom-0 size-[32rem] rounded-full bg-gold-100/75 blur-3xl"></div>

        <header class="relative z-10 border-b border-white/70 bg-paper/60 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-page-desktop py-5">
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-11 place-items-center rounded-full border border-gold-300 bg-white/75 font-display text-xl text-rose-600 shadow-sm"
                        aria-hidden="true"
                    >
                        囍
                    </span>
                    <div>
                        <p class="font-display text-lg font-semibold tracking-wide text-ink-950">我们的婚礼相册</p>
                        <p class="text-[0.68rem] tracking-[0.22em] text-ink-500 uppercase">Wedding Memories</p>
                    </div>
                </div>
                <span class="rounded-pill border border-rose-100 bg-white/75 px-4 py-2 text-sm font-semibold text-rose-700">
                    {{ sectionLabel }}
                </span>
            </div>
        </header>

        <main class="relative z-10 mx-auto flex max-w-7xl flex-col gap-8 px-page-desktop py-10 lg:py-14">
            <slot />
        </main>
    </div>
</template>
