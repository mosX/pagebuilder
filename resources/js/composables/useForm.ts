import { ElMessage } from 'element-plus';
import { reactive, toRaw } from 'vue';
import { isValidationError, send } from '@/lib/http';
import type { ApiRoute } from '@/lib/http';

type FormState<T extends Record<string, unknown>> = T & {
    errors: Partial<Record<keyof T | string, string>>;
    processing: boolean;
    readonly isDirty: boolean;
    data(): T;
    submit<R = unknown>(route: ApiRoute): Promise<R | null>;
    reset(...fields: (keyof T)[]): void;
    defaults(): void;
    clearErrors(): void;
};

/**
 * Small form helper for the API: keeps field values, maps 422 errors to fields
 * and resolves with the response body, or null when the request failed.
 */
export function useForm<T extends Record<string, unknown>>(
    initial: T,
): FormState<T> {
    let defaults = structuredClone(initial);
    const keys = Object.keys(initial) as (keyof T)[];

    const form = reactive({
        ...structuredClone(initial),
        errors: {},
        processing: false,

        get isDirty(): boolean {
            return keys.some(
                (key) =>
                    JSON.stringify((form as T)[key]) !==
                    JSON.stringify(defaults[key]),
            );
        },

        data(): T {
            return Object.fromEntries(
                keys.map((key) => [key, (toRaw(form) as T)[key]]),
            ) as T;
        },

        async submit<R>(route: ApiRoute): Promise<R | null> {
            form.processing = true;
            form.errors = {};

            try {
                return await send<R>(route, form.data());
            } catch (error) {
                if (isValidationError(error)) {
                    const errors = error.response?.data.errors ?? {};

                    form.errors = Object.fromEntries(
                        Object.entries(errors).map(([key, messages]) => [
                            key,
                            messages[0],
                        ]),
                    );

                    // Errors that no field can display (e.g. `member`) become a toast.
                    if (
                        !Object.keys(errors).some((key) => keys.includes(key))
                    ) {
                        ElMessage.error(error.response?.data.message ?? '');
                    }
                }

                return null;
            } finally {
                form.processing = false;
            }
        },

        reset(...fields: (keyof T)[]) {
            for (const key of fields.length ? fields : keys) {
                (form as T)[key] = structuredClone(defaults[key]);
            }
        },

        defaults() {
            defaults = structuredClone(form.data());
        },

        clearErrors() {
            form.errors = {};
        },
    });

    return form as unknown as FormState<T>;
}
