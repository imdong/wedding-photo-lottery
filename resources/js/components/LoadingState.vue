<script setup lang="ts">
withDefaults(
    defineProps<{
        label?: string;
    }>(),
    {
        label: '正在加载',
    },
);
</script>

<template>
    <div class="inline-flex items-center gap-2.5 text-sm font-medium text-ink-500" role="status" aria-live="polite">
        <span class="size-4 animate-spin rounded-full border-2 border-rose-100 border-t-rose-600" aria-hidden="true"></span>
        <span>{{ label }}</span>
    </div>
</template>
