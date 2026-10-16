import axios, { AxiosError } from 'axios';
import { ElMessage } from 'element-plus';

/**
 * Axios instance for the Laravel API. Sanctum SPA auth works over the session cookie,
 * so requests carry credentials and the XSRF-TOKEN cookie is echoed back as a header.
 */
export const http = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Shape of a Wayfinder route call, e.g. `members.update(id)`.
 */
export type ApiRoute = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete' | 'head';
};

export type ApiError = AxiosError<{
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
}>;

type Handlers = {
    onUnauthenticated: () => void;
    onNoTenant: () => void;
};

let handlers: Handlers | null = null;

/**
 * Lets the app react to session-level failures without http.ts importing the router or stores.
 */
export function setHttpHandlers(value: Handlers) {
    handlers = value;
}

http.interceptors.response.use(
    (response) => response,
    (error: ApiError) => {
        const status = error.response?.status;
        const data = error.response?.data;

        if (status === 401) {
            handlers?.onUnauthenticated();
        } else if (status === 409 && data?.code === 'no_tenant') {
            handlers?.onNoTenant();
        } else if (status === 419) {
            ElMessage.error('Сессия устарела, обновите страницу.');
        } else if (status !== 422) {
            // Validation errors are rendered by forms; everything else gets a toast.
            ElMessage.error(
                data?.message || 'Произошла ошибка, попробуйте ещё раз.',
            );
        }

        return Promise.reject(error);
    },
);

/**
 * Calls a Wayfinder route definition: `send(members.update(id), { role })`.
 */
export async function send<T = unknown>(
    route: ApiRoute,
    data?: unknown,
): Promise<T> {
    const isRead = route.method === 'get' || route.method === 'head';

    const response = await http.request<T>({
        url: route.url,
        method: route.method,
        ...(isRead ? { params: data } : { data }),
    });

    return response.data;
}

export function isValidationError(error: unknown): error is ApiError {
    return axios.isAxiosError(error) && error.response?.status === 422;
}
