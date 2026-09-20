import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { isAxiosError } from 'axios';
import api, { setApiAccessToken, userTokenStorageKey } from '../services/api';

export interface UserIdentity {
    id: number;
    phone: string;
    invite_code: string;
}

interface PhoneAuthenticationResponse {
    token: string;
    user: UserIdentity;
}

interface ApiErrorResponse {
    message?: string;
    errors?: {
        phone?: string[];
    };
}

type IdentityStatus = 'idle' | 'restoring' | 'restore-error' | 'guest' | 'registering' | 'authenticated';

export const useUserIdentityStore = defineStore('userIdentity', () => {
    const user = ref<UserIdentity | null>(null);
    const status = ref<IdentityStatus>('idle');
    const errorMessage = ref('');

    const isAuthenticated = computed(() => status.value === 'authenticated' && user.value !== null);
    const isRegistering = computed(() => status.value === 'registering');

    async function restoreIdentity(force = false): Promise<void> {
        if (!force && status.value !== 'idle') {
            return;
        }

        const storedToken = localStorage.getItem(userTokenStorageKey);

        if (storedToken === null) {
            status.value = 'guest';

            return;
        }

        status.value = 'restoring';
        errorMessage.value = '';
        setApiAccessToken(storedToken);

        try {
            const response = await api.get<UserIdentity>('/me');

            user.value = response.data;
            status.value = 'authenticated';
        } catch (error: unknown) {
            if (isAxiosError(error) && error.response?.status === 401) {
                clearStoredIdentity();
                status.value = 'guest';

                return;
            }

            errorMessage.value = '暂时无法连接，请检查网络后重试。';
            status.value = 'restore-error';
        }
    }

    async function registerPhone(phone: string): Promise<boolean> {
        status.value = 'registering';
        errorMessage.value = '';

        try {
            const response = await api.post<PhoneAuthenticationResponse>('/auth/phone', { phone });

            localStorage.setItem(userTokenStorageKey, response.data.token);
            setApiAccessToken(response.data.token);
            user.value = response.data.user;
            status.value = 'authenticated';

            return true;
        } catch (error: unknown) {
            errorMessage.value = getRegistrationErrorMessage(error);
            status.value = 'guest';

            return false;
        }
    }

    function clearStoredIdentity(): void {
        localStorage.removeItem(userTokenStorageKey);
        setApiAccessToken(null);
        user.value = null;
    }

    return {
        errorMessage,
        isAuthenticated,
        isRegistering,
        registerPhone,
        restoreIdentity,
        status,
        user,
    };
});

function getRegistrationErrorMessage(error: unknown): string {
    if (!isAxiosError<ApiErrorResponse>(error)) {
        return '登记失败，请稍后重试。';
    }

    return error.response?.data.errors?.phone?.[0] ?? error.response?.data.message ?? '登记失败，请稍后重试。';
}
