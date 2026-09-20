<script setup lang="ts">
import { ref } from 'vue';
import BaseButton from './BaseButton.vue';
import LoadingState from './LoadingState.vue';
import { useUserIdentityStore } from '../stores/userIdentity';

const userIdentity = useUserIdentityStore();
const phone = ref('');
const localError = ref('');

async function submit(): Promise<void> {
    const normalizedPhone = phone.value.trim();

    if (!/^1[3-9]\d{9}$/.test(normalizedPhone)) {
        localError.value = '请输入有效的 11 位手机号。';

        return;
    }

    localError.value = '';
    await userIdentity.registerPhone(normalizedPhone);
}

function retryRestore(): void {
    void userIdentity.restoreIdentity(true);
}
</script>

<template>
    <div
        v-if="!userIdentity.isAuthenticated"
        class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-ink-950/38 px-page-mobile py-8 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="phone-registration-title"
    >
        <section class="w-full max-w-sm rounded-card border border-white/80 bg-paper p-6 shadow-shell sm:p-7">
            <div v-if="userIdentity.status === 'idle' || userIdentity.status === 'restoring'" class="flex min-h-56 flex-col items-center justify-center gap-5 text-center">
                <span class="grid size-14 place-items-center rounded-full border border-gold-300/70 bg-white font-display text-2xl text-rose-600" aria-hidden="true">囍</span>
                <LoadingState label="正在恢复身份" />
            </div>

            <div v-else-if="userIdentity.status === 'restore-error'" class="flex min-h-56 flex-col items-center justify-center gap-5 text-center">
                <span class="grid size-14 place-items-center rounded-full bg-rose-50 text-2xl text-rose-600" aria-hidden="true">!</span>
                <div class="flex flex-col gap-2">
                    <h2 id="phone-registration-title" class="font-display text-2xl font-semibold text-ink-950">暂时无法连接</h2>
                    <p class="text-sm leading-6 text-ink-500">{{ userIdentity.errorMessage }}</p>
                </div>
                <BaseButton full-width @click="retryRestore">重新连接</BaseButton>
            </div>

            <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="flex flex-col items-center gap-3 text-center">
                    <span class="grid size-14 place-items-center rounded-full border border-gold-300/70 bg-white font-display text-2xl text-rose-600 shadow-sm" aria-hidden="true">囍</span>
                    <div class="flex flex-col gap-2">
                        <p class="text-xs font-semibold tracking-[0.22em] text-rose-600 uppercase">Welcome</p>
                        <h2 id="phone-registration-title" class="font-display text-3xl font-semibold text-ink-950">留下你的手机号</h2>
                        <p class="text-sm leading-6 text-ink-500">用于记住你的照片和点赞记录，不会发送验证码。</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label for="guest-phone" class="text-sm font-semibold text-ink-700">手机号</label>
                    <input
                        id="guest-phone"
                        v-model="phone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel"
                        maxlength="11"
                        placeholder="请输入 11 位手机号"
                        :disabled="userIdentity.isRegistering"
                        class="min-h-13 w-full rounded-control border border-rose-100 bg-white px-4 text-base text-ink-950 outline-none transition placeholder:text-ink-500/65 focus:border-rose-500 focus:ring-3 focus:ring-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                        aria-describedby="phone-registration-hint"
                    />
                    <p
                        id="phone-registration-hint"
                        :class="['min-h-5 text-xs leading-5', localError || userIdentity.errorMessage ? 'text-rose-700' : 'text-ink-500']"
                        aria-live="polite"
                    >
                        {{ localError || userIdentity.errorMessage || '下次打开时会自动恢复，无需再次输入。' }}
                    </p>
                </div>

                <BaseButton native-type="submit" full-width :disabled="userIdentity.isRegistering">
                    <LoadingState v-if="userIdentity.isRegistering" label="正在进入相册" class="text-white" />
                    <span v-else>进入婚礼相册</span>
                </BaseButton>
            </form>
        </section>
    </div>
</template>
