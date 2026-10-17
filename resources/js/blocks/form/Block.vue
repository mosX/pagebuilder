<script setup lang="ts">
import { inject, reactive, ref } from 'vue';
import { currentSiteKey } from '@/site/current-page';

type FormField = {
    name: string;
    label: string;
    type: string;
    required: boolean;
    choices: string[] | null;
};

type FormData = {
    fields: FormField[];
    /** Issued by the server for published pages only; absent in the editor preview. */
    token: string | null;
    error: string | null;
};

const props = defineProps<{
    block: {
        collection: string;
        title: string;
        submit_label: string;
        success_message: string;
    };
    data?: FormData | null;
}>();

const values = reactive<Record<string, unknown>>({});

// A signed-in visitor does not type their name and e-mail again.
const member = inject(currentSiteKey, null)?.value?.account?.member;

if (member) {
    for (const field of props.data?.fields ?? []) {
        if (field.type === 'email') {
            values[field.name] = member.email;
        } else if (field.name === 'name') {
            values[field.name] = member.name;
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

async function submit() {
    if (!props.data?.token) {
        return;
    }

    sending.value = true;
    errors.value = {};
    message.value = '';

    try {
        const response = await fetch('/api/public/forms', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                token: props.data.token,
                values,
                website: website.value || undefined,
            }),
        });
        const body = await response.json().catch(() => ({}));

        if (response.ok) {
            sent.value = true;
        } else if (response.status === 422) {
            errors.value = Object.fromEntries(
                Object.entries(
                    (body.errors ?? {}) as Record<string, string[]>,
                ).map(([key, list]) => [key, list[0]]),
            );
            message.value = errors.value.form ?? 'Проверьте поля формы.';
        } else if (response.status === 429) {
            message.value = 'Слишком много попыток. Попробуйте через минуту.';
        } else {
            message.value = 'Не удалось отправить. Попробуйте ещё раз.';
        }
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
