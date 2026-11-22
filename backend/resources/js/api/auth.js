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
