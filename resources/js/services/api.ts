import axios from 'axios';

export const userTokenStorageKey = 'wedding-photo-lottery.user-token';

const api = axios.create({
    baseURL: '/api',
    timeout: 5_000,
    headers: {
        Accept: 'application/json',
    },
});

export function setApiAccessToken(token: string | null): void {
    if (token === null) {
        delete api.defaults.headers.common.Authorization;

        return;
    }

    api.defaults.headers.common.Authorization = `Bearer ${token}`;
}

export default api;
