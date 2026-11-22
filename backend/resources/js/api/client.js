import axios from 'axios';

const TOKEN_KEY = 'learnloop_token';

export const tokenStore = {
    get: () => localStorage.getItem(TOKEN_KEY),
    set: (token) => localStorage.setItem(TOKEN_KEY, token),
    clear: () => localStorage.removeItem(TOKEN_KEY),
};

// Dispatched when the API says our token is no longer valid, so the auth
// layer can sign the user out without this module importing React.
export const UNAUTHENTICATED_EVENT = 'learnloop:unauthenticated';

const client = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
});

client.interceptors.request.use((config) => {
    const token = tokenStore.get();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

client.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && tokenStore.get()) {
            tokenStore.clear();
            window.dispatchEvent(new Event(UNAUTHENTICATED_EVENT));
        }
        return Promise.reject(error);
    },
);

/** Turn an axios error into { message, fieldErrors } for forms. */
export function parseApiError(error) {
    const data = error.response?.data;
    return {
        message: data?.message ?? 'Something went wrong. Please try again.',
        fieldErrors: data?.errors ?? {},
    };
}

export default client;
