<script setup lang="ts">
import { computed } from 'vue';

type ButtonVariant = 'primary' | 'secondary' | 'quiet';

const props = withDefaults(
    defineProps<{
        disabled?: boolean;
        fullWidth?: boolean;
        variant?: ButtonVariant;
    }>(),
    {
        disabled: false,
        fullWidth: false,
        variant: 'primary',
    },
);

const variantClasses = computed(() => {
    const variants: Record<ButtonVariant, string> = {
        primary:
            'bg-rose-600 text-white shadow-[0_10px_24px_rgb(168_85_99_/_0.24)] enabled:hover:bg-rose-700',
        secondary: 'border border-rose-100 bg-white/80 text-rose-700 enabled:hover:bg-rose-50',
        quiet: 'bg-gold-100 text-ink-700 enabled:hover:bg-gold-300/55',
    };

    return variants[props.variant];
});
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :class="[
            'inline-flex min-h-12 items-center justify-center gap-2 rounded-pill px-6 py-3 text-sm font-semibold tracking-wide transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500 enabled:hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-45',
            variantClasses,
            fullWidth ? 'w-full' : '',
        ]"
    >
        <slot />
    </button>
</template>
