import client from './client';

// Thin wrappers over /api/auth/*. No business logic here.
export const login = (email, password) =>
    client.post('/auth/login', { email, password }).then((r) => r.data);

export const register = ({ name, email, password, password_confirmation }) =>
    client
        .post('/auth/register', { name, email, password, password_confirmation })
        .then((r) => r.data);

export const logout = () => client.post('/auth/logout').then((r) => r.data);

export const fetchMe = () => client.get('/auth/me').then((r) => r.data.user);

// §14 Sign in with Canvas (OAuth2; public endpoints)
export const fetchCanvasAuthStatus = () =>
    client.get('/auth/canvas').then((r) => r.data);

export const startCanvasAuthLogin = () =>
    client.post('/auth/canvas').then((r) => r.data);

export const CANVAS_ERROR_MESSAGES = {
    denied: 'Canvas sign-in was cancelled.',
    expired: 'That Canvas sign-in session took too long and expired. Please try again.',
    failed: 'Canvas refused the request. Please try again.',
    already_linked: 'This Canvas account already belongs to another LearnLoop account.',
    email_in_use:
        'An account with your email exists: log in with your password, then use Connect with Canvas.',
};
