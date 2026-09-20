<script setup lang="ts">
withDefaults(
    defineProps<{
        align?: 'left' | 'center';
        description?: string;
        eyebrow: string;
        title: string;
    }>(),
    {
        align: 'left',
        description: undefined,
    },
);
</script>

<template>
    <header :class="['flex flex-col gap-3', align === 'center' ? 'items-center text-center' : 'items-start text-left']">
        <p class="text-xs font-semibold tracking-[0.24em] text-rose-600 uppercase">{{ eyebrow }}</p>
        <h1 class="max-w-3xl font-display text-4xl leading-[1.15] font-semibold tracking-tight text-ink-950 md:text-5xl">
            {{ title }}
        </h1>
        <p v-if="description" class="max-w-2xl text-sm leading-6 text-ink-500 md:text-base md:leading-7">
            {{ description }}
        </p>
    </header>
</template>
