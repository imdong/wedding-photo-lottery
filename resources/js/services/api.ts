import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    timeout: 5_000,
    headers: {
        Accept: 'application/json',
    },
});

export default api;
