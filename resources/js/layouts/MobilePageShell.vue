<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
    <div class="min-h-dvh bg-canvas sm:py-6">
        <div
            class="relative mx-auto flex min-h-dvh max-w-[30rem] flex-col overflow-hidden bg-paper sm:min-h-[calc(100dvh-3rem)] sm:rounded-[2.25rem] sm:border sm:border-white/70 sm:shadow-shell"
        >
            <div class="pointer-events-none absolute -top-24 -right-24 size-64 rounded-full bg-rose-100/80 blur-3xl"></div>
            <div class="pointer-events-none absolute top-56 -left-32 size-64 rounded-full bg-gold-100/75 blur-3xl"></div>

            <header class="relative z-10 flex items-center justify-between px-page-mobile pt-6 pb-3">
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-10 place-items-center rounded-full border border-gold-300/70 bg-white/70 font-display text-lg text-rose-600 shadow-sm"
                        aria-hidden="true"
                    >
                        囍
                    </span>
                    <div>
                        <p class="font-display text-base font-semibold tracking-wide text-ink-950">我们的婚礼相册</p>
                        <p class="text-[0.68rem] tracking-[0.2em] text-ink-500 uppercase">Wedding Memories</p>
                    </div>
                </div>
                <span class="rounded-pill border border-rose-100 bg-white/65 px-3 py-1 text-xs font-medium text-rose-700">喜宴现场</span>
            </header>

            <main class="relative z-10 flex flex-1 flex-col gap-section px-page-mobile pt-8 pb-28">
                <slot />
            </main>

            <nav
                class="fixed right-0 bottom-0 left-0 z-20 mx-auto max-w-[30rem] border-t border-rose-100/80 bg-paper/90 px-page-mobile py-3 backdrop-blur-xl sm:bottom-6 sm:rounded-b-[2.25rem]"
                aria-label="用户页面"
            >
                <div class="grid grid-cols-2 gap-2 rounded-[1.35rem] bg-white/70 p-1.5 shadow-sm ring-1 ring-rose-100/70">
                    <RouterLink
                        to="/"
                        class="rounded-control px-4 py-3 text-center text-sm font-semibold text-ink-500 transition hover:bg-rose-50"
                        exact-active-class="bg-rose-600 text-white shadow-[0_8px_18px_rgb(168_85_99_/_0.2)]"
                    >
                        上传照片
                    </RouterLink>
                    <RouterLink
                        to="/gallery"
                        class="rounded-control px-4 py-3 text-center text-sm font-semibold text-ink-500 transition hover:bg-rose-50"
                        exact-active-class="bg-rose-600 text-white shadow-[0_8px_18px_rgb(168_85_99_/_0.2)]"
                    >
                        照片墙
                    </RouterLink>
                </div>
            </nav>
        </div>
    </div>
</template>
