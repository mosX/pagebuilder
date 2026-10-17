<script setup lang="ts">
import { inject, onMounted, reactive, ref } from 'vue';
import { currentSiteKey } from '@/site/current-page';

type FormField = {
    name: string;
    label: string;
    type: string;
    required: boolean;
    choices: string[] | null;
};

type Captcha = {
    provider: 'yandex' | 'turnstile';
    site_key: string;
    script: string;
};

type FormData = {
    fields: FormField[];
    /** Issued by the server for published pages only; absent in the editor preview. */
    token: string | null;
    error: string | null;
    /** The captcha widget, when the platform has one and the form uses it. */
    captcha?: Captcha | null;
};

type CaptchaApi = {
    render: (el: HTMLElement, options: Record<string, unknown>) => unknown;
    reset: (id?: unknown) => void;
};

declare global {
    interface Window {
        smartCaptcha?: CaptchaApi;
        turnstile?: CaptchaApi;
    }
}

const props = defineProps<{
    block: {
        collection: string;
        title: string;
        submit_label: string;
        success_message: string;
    };
    data?: FormData | null;
}>();

/** What visitors may attach; the server checks the files by content as well. */
const fileTypes =
    '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.zip,.jpg,.jpeg,.png,.webp,.gif,.avif';

const values = reactive<Record<string, unknown>>({});

// A signed-in visitor does not type their name, e-mail or profile details again.
const member = inject(currentSiteKey, null)?.value?.account?.member;

if (member) {
    for (const field of props.data?.fields ?? []) {
        if (field.type === 'email') {
            values[field.name] = member.email;
        } else if (field.name === 'name') {
            values[field.name] = member.name;
        } else if (member.profile?.[field.name]) {
            values[field.name] = member.profile[field.name];
        }
    }
}

const errors = ref<Record<string, string>>({});
const message = ref('');
const sending = ref(false);
const sent = ref(false);
// Honeypot: invisible to people, bots fill it in.
const website = ref('');

const inputTypes: Record<string, string> = {
    email: 'email',
    url: 'url',
    integer: 'number',
    decimal: 'number',
    date: 'date',
    datetime: 'datetime-local',
};

// --- Captcha ---

const captchaEl = ref<HTMLElement | null>(null);
const captchaToken = ref('');
let captchaId: unknown;

function captchaApi(): CaptchaApi | undefined {
    return props.data?.captcha?.provider === 'yandex'
        ? window.smartCaptcha
        : window.turnstile;
}

function loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(
            `script[src="${src}"]`,
        );

        if (existing && captchaApi()) {
            resolve();

            return;
        }

        const script = existing ?? document.createElement('script');
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => reject(new Error('captcha')));

        if (!existing) {
            script.src = src;
            script.async = true;
            document.head.appendChild(script);
        }
    });
}

onMounted(async () => {
    const captcha = props.data?.captcha;

    if (!captcha || !props.data?.token || !captchaEl.value) {
        return;
    }

    try {
        await loadScript(captcha.script);
        captchaId = captchaApi()?.render(captchaEl.value, {
            sitekey: captcha.site_key,
            callback: (token: string) => (captchaToken.value = token),
        });
    } catch {
        message.value =
            'Не удалось загрузить проверку «я не робот». Обновите страницу.';
    }
});

function resetCaptcha() {
    captchaToken.value = '';
    captchaApi()?.reset(captchaId);
}

// --- Submit ---

function hasFiles() {
    return (props.data?.fields ?? []).some(
        (f) => f.type === 'file' && values[f.name] instanceof File,
    );
}

/** Files need a multipart request; everything else goes as JSON. */
function body(token: string): {
    body: BodyInit;
    headers: Record<string, string>;
} {
    if (!hasFiles()) {
        return {
            body: JSON.stringify({
                token,
                values,
                website: website.value || undefined,
                captcha: captchaToken.value || undefined,
            }),
            headers: { 'Content-Type': 'application/json' },
        };
    }

    const form = new window.FormData();
    form.append('token', token);
    form.append('captcha', captchaToken.value);

    if (website.value) {
        form.append('website', website.value);
    }

    for (const [name, value] of Object.entries(values)) {
        if (value === undefined || value === null || value === '') {
            continue;
        }

        form.append(
            `values[${name}]`,
            value instanceof File
                ? value
                : typeof value === 'boolean'
                  ? value
                      ? '1'
                      : '0'
                  : String(value),
        );
    }

    return { body: form, headers: {} };
}

function pickFile(field: FormField, event: Event) {
    values[field.name] =
        (event.target as HTMLInputElement).files?.[0] ?? undefined;
}

async function submit() {
    if (!props.data?.token) {
        return;
    }

    if (props.data.captcha && !captchaToken.value) {
        message.value = 'Подтвердите, что вы не робот.';

        return;
    }

    sending.value = true;
    errors.value = {};
    message.value = '';

    try {
        const request = body(props.data.token);
        const response = await fetch('/api/public/forms', {
            method: 'POST',
            headers: { ...request.headers, Accept: 'application/json' },
            body: request.body,
        });
        const result = await response.json().catch(() => ({}));

        if (response.ok) {
            sent.value = true;

            return;
        }

        if (response.status === 422) {
            errors.value = Object.fromEntries(
                Object.entries(
                    (result.errors ?? {}) as Record<string, string[]>,
                ).map(([key, list]) => [key.replace(/^values\./, ''), list[0]]),
            );
            message.value =
                errors.value.form ??
                errors.value.captcha ??
                'Проверьте поля формы.';
        } else if (response.status === 413) {
            message.value = 'Файл слишком большой.';
        } else if (response.status === 429) {
            message.value = 'Слишком много попыток. Попробуйте через минуту.';
        } else {
            message.value = 'Не удалось отправить. Попробуйте ещё раз.';
        }

        resetCaptcha();
    } catch {
        message.value = 'Нет соединения. Попробуйте ещё раз.';
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-xl flex-col gap-4">
        <h2
            v-if="block.title"
            class="font-heading text-2xl font-semibold text-ink"
        >
            {{ block.title }}
        </h2>

        <div
            v-if="!block.collection || !data"
            class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400"
        >
            Выберите коллекцию для заявок в настройках блока
        </div>
        <div
            v-else-if="data.error"
            class="rounded-lg bg-red-50 p-4 text-sm text-red-700"
        >
            {{ data.error }}
        </div>
        <div
            v-else-if="sent"
            class="rounded-lg bg-green-50 p-6 text-center text-green-800"
        >
            {{ block.success_message }}
        </div>

        <form
            v-else
            class="flex flex-col gap-4"
            novalidate
            @submit.prevent="submit"
        >
            <label
                v-for="field in data.fields"
                :key="field.name"
                class="flex flex-col gap-1 text-sm"
                :class="{
                    'flex-row items-center gap-2': field.type === 'boolean',
                }"
            >
                <span
                    v-if="field.type !== 'boolean'"
                    class="font-medium text-gray-700"
                >
                    {{ field.label }}
                    <span v-if="field.required" class="text-red-500">*</span>
                </span>

                <textarea
                    v-if="field.type === 'text'"
                    v-model="values[field.name] as string"
                    rows="4"
                    :required="field.required"
                    class="rounded-site border border-gray-300 px-3 py-2 focus:border-brand focus:outline-none"
                />
                <select
                    v-else-if="field.type === 'select'"
                    v-model="values[field.name]"
                    :required="field.required"
                    class="rounded-site border border-gray-300 px-3 py-2 focus:border-brand focus:outline-none"
                >
                    <option :value="undefined">—</option>
                    <option
                        v-for="choice in field.choices ?? []"
                        :key="choice"
                        :value="choice"
                    >
                        {{ choice }}
                    </option>
                </select>
                <input
                    v-else-if="field.type === 'boolean'"
                    v-model="values[field.name] as boolean"
                    type="checkbox"
                    class="h-4 w-4"
                />
                <input
                    v-else-if="field.type === 'file'"
                    type="file"
                    :accept="fileTypes"
                    :required="field.required"
                    class="rounded-site border border-dashed border-gray-300 px-3 py-2 text-gray-600 file:mr-3 file:rounded file:border-0 file:bg-brand/10 file:px-3 file:py-1 file:text-brand"
                    @change="pickFile(field, $event)"
                />
                <input
                    v-else
                    v-model="values[field.name] as string"
                    :type="inputTypes[field.type] ?? 'text'"
                    :step="field.type === 'decimal' ? 'any' : undefined"
                    :required="field.required"
                    class="rounded-site border border-gray-300 px-3 py-2 focus:border-brand focus:outline-none"
                />

                <span v-if="field.type === 'boolean'" class="text-gray-700">
                    {{ field.label }}
                </span>
                <span
                    v-if="field.type === 'file'"
                    class="text-xs text-gray-400"
                >
                    PDF, документы Office, изображения или ZIP — до 10 МБ
                </span>
                <span v-if="errors[field.name]" class="text-xs text-red-600">
                    {{ errors[field.name] }}
                </span>
            </label>

            <input
                v-model="website"
                type="text"
                name="website"
                tabindex="-1"
                autocomplete="off"
                aria-hidden="true"
                class="absolute -left-[9999px] h-px w-px opacity-0"
            />

            <div
                v-if="data.captcha && data.token"
                ref="captchaEl"
                class="min-h-16"
            />

            <p v-if="message" class="text-sm text-red-600">{{ message }}</p>
            <p v-if="!data.token" class="text-xs text-gray-400">
                В редакторе форма не отправляется — только на опубликованной
                странице.
            </p>

            <button
                type="submit"
                :disabled="sending || !data.token"
                class="self-start rounded-site bg-brand px-5 py-2.5 font-medium text-brand-contrast transition hover:bg-brand/90 disabled:opacity-50"
            >
                {{ sending ? 'Отправка…' : block.submit_label }}
            </button>
        </form>
    </div>
</template>
