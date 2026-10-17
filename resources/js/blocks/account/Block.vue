<script setup lang="ts">
import { computed } from 'vue';

type Submission = {
    label: string;
    items: {
        title: string;
        date: string | null;
        /** The submission's status, when the company tracks one. */
        status: string | null;
        fields: { label: string; value: string }[];
    }[];
};

/**
 * Sign-in, sign-up and the personal area of site visitors. Plain HTML forms posted
 * to the server (with the CSRF token), so they work before and without JavaScript.
 */
const props = defineProps<{
    block: Record<string, never>;
    /** Filled by the server on the /account pages. */
    data?: {
        view: 'login' | 'register' | 'cabinet' | 'forgot' | 'reset';
        /** The address from the reset link. */
        email?: string;
        csrf: string;
        status: string | null;
        errors: Record<string, string>;
        old: {
            name?: string;
            email?: string;
            profile?: Record<string, string>;
        };
        /** Extra profile fields of the site (phone…). */
        profile_fields?: {
            name: string;
            label: string;
            type: string;
            required: boolean;
        }[];
        redirect?: string | null;
        actions: Record<string, string | null>;
        member?: {
            name: string;
            email: string;
            verified: boolean;
            profile: Record<string, string>;
        };
        submissions?: Submission[];
    };
}>();

const errors = computed(() => props.data?.errors ?? {});

const input =
    'w-full rounded-site border border-gray-300 bg-surface px-3 py-2 text-ink focus:border-brand focus:outline-none';
const button =
    'rounded-site bg-brand px-5 py-2.5 font-medium text-brand-contrast transition hover:bg-brand/90';

/** DD.MM.YYYY from the stored date, the same on the server and in the browser. */
function date(value: string | null) {
    const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})/);

    return match ? `${match[3]}.${match[2]}.${match[1]}` : '';
}
</script>

<template>
    <section v-if="data" class="px-4 py-12 @3xl:py-16">
        <div
            class="mx-auto flex flex-col gap-6"
            :class="data.view === 'cabinet' ? 'max-w-3xl' : 'max-w-md'"
        >
            <p
                v-if="data.status"
                class="rounded-site bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                {{ data.status }}
            </p>

            <!-- Sign in -->
            <template v-if="data.view === 'login'">
                <h1 class="font-heading text-3xl font-bold text-ink">Вход</h1>
                <form
                    :action="data.actions.login ?? undefined"
                    method="post"
                    class="flex flex-col gap-4"
                >
                    <input type="hidden" name="_token" :value="data.csrf" />
                    <input
                        v-if="data.redirect"
                        type="hidden"
                        name="redirect"
                        :value="data.redirect"
                    />
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">E-mail</span>
                        <input
                            type="email"
                            name="email"
                            :value="data.old.email"
                            required
                            autocomplete="email"
                            :class="input"
                        />
                        <span v-if="errors.email" class="text-xs text-red-600">
                            {{ errors.email }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">Пароль</span>
                        <input
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            :class="input"
                        />
                        <span
                            v-if="errors.password"
                            class="text-xs text-red-600"
                        >
                            {{ errors.password }}
                        </span>
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm text-gray-700"
                    >
                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            class="h-4 w-4"
                        />
                        Запомнить меня
                    </label>
                    <button type="submit" :class="button">Войти</button>
                </form>
                <p v-if="data.actions.forgot" class="text-sm">
                    <a
                        :href="data.actions.forgot"
                        class="text-brand hover:underline"
                        >Забыли пароль?</a
                    >
                </p>
                <p v-if="data.actions.register" class="text-sm text-gray-600">
                    Нет аккаунта?
                    <a
                        :href="data.actions.register"
                        class="text-brand hover:underline"
                        >Зарегистрируйтесь</a
                    >
                </p>
            </template>

            <!-- Forgotten password -->
            <template v-else-if="data.view === 'forgot'">
                <h1 class="font-heading text-3xl font-bold text-ink">
                    Восстановление пароля
                </h1>
                <p class="text-sm text-gray-600">
                    Укажите e-mail аккаунта — пришлём ссылку, по которой можно
                    задать новый пароль.
                </p>
                <form
                    :action="data.actions.forgot ?? undefined"
                    method="post"
                    class="flex flex-col gap-4"
                >
                    <input type="hidden" name="_token" :value="data.csrf" />
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">E-mail</span>
                        <input
                            type="email"
                            name="email"
                            :value="data.old.email"
                            required
                            autocomplete="email"
                            :class="input"
                        />
                        <span v-if="errors.email" class="text-xs text-red-600">
                            {{ errors.email }}
                        </span>
                    </label>
                    <button type="submit" :class="button">
                        Отправить ссылку
                    </button>
                </form>
                <p class="text-sm text-gray-600">
                    <a
                        :href="data.actions.login ?? undefined"
                        class="text-brand hover:underline"
                        >Вернуться ко входу</a
                    >
                </p>
            </template>

            <!-- New password by the link from the e-mail -->
            <template v-else-if="data.view === 'reset'">
                <h1 class="font-heading text-3xl font-bold text-ink">
                    Новый пароль
                </h1>
                <form
                    :action="data.actions.reset ?? undefined"
                    method="post"
                    class="flex flex-col gap-4"
                >
                    <input type="hidden" name="_token" :value="data.csrf" />
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">E-mail</span>
                        <input
                            type="email"
                            name="email"
                            :value="data.old.email ?? data.email"
                            required
                            autocomplete="email"
                            :class="input"
                        />
                        <span v-if="errors.email" class="text-xs text-red-600">
                            {{ errors.email }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700"
                            >Новый пароль</span
                        >
                        <input
                            type="password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            :class="input"
                        />
                        <span
                            v-if="errors.password"
                            class="text-xs text-red-600"
                        >
                            {{ errors.password }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700"
                            >Пароль ещё раз</span
                        >
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                            :class="input"
                        />
                    </label>
                    <button type="submit" :class="button">
                        Сохранить пароль
                    </button>
                </form>
            </template>

            <!-- Sign up -->
            <template v-else-if="data.view === 'register'">
                <h1 class="font-heading text-3xl font-bold text-ink">
                    Регистрация
                </h1>
                <form
                    :action="data.actions.register ?? undefined"
                    method="post"
                    class="flex flex-col gap-4"
                >
                    <input type="hidden" name="_token" :value="data.csrf" />
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">Имя</span>
                        <input
                            type="text"
                            name="name"
                            :value="data.old.name"
                            required
                            autocomplete="name"
                            :class="input"
                        />
                        <span v-if="errors.name" class="text-xs text-red-600">
                            {{ errors.name }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">E-mail</span>
                        <input
                            type="email"
                            name="email"
                            :value="data.old.email"
                            required
                            autocomplete="email"
                            :class="input"
                        />
                        <span v-if="errors.email" class="text-xs text-red-600">
                            {{ errors.email }}
                        </span>
                    </label>
                    <label
                        v-for="field in data.profile_fields ?? []"
                        :key="field.name"
                        class="flex flex-col gap-1 text-sm"
                    >
                        <span class="font-medium text-gray-700">
                            {{ field.label }}
                            <span v-if="field.required" class="text-red-500"
                                >*</span
                            >
                        </span>
                        <input
                            :type="field.type === 'phone' ? 'tel' : 'text'"
                            :name="`profile[${field.name}]`"
                            :value="data.old.profile?.[field.name]"
                            :required="field.required"
                            :autocomplete="
                                field.type === 'phone' ? 'tel' : 'on'
                            "
                            :class="input"
                        />
                        <span
                            v-if="errors[`profile.${field.name}`]"
                            class="text-xs text-red-600"
                        >
                            {{ errors[`profile.${field.name}`] }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700">Пароль</span>
                        <input
                            type="password"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            :class="input"
                        />
                        <span
                            v-if="errors.password"
                            class="text-xs text-red-600"
                        >
                            {{ errors.password }}
                        </span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium text-gray-700"
                            >Пароль ещё раз</span
                        >
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                            :class="input"
                        />
                    </label>
                    <button type="submit" :class="button">
                        Зарегистрироваться
                    </button>
                </form>
                <p class="text-sm text-gray-600">
                    Уже есть аккаунт?
                    <a
                        :href="data.actions.login ?? undefined"
                        class="text-brand hover:underline"
                        >Войдите</a
                    >
                </p>
            </template>

            <!-- Personal area -->
            <template v-else-if="data.member">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="font-heading text-3xl font-bold text-ink">
                            {{ data.member.name }}
                        </h1>
                        <p class="text-sm text-gray-500">
                            {{ data.member.email }}
                        </p>
                    </div>
                    <form
                        :action="data.actions.logout ?? undefined"
                        method="post"
                    >
                        <input type="hidden" name="_token" :value="data.csrf" />
                        <button
                            type="submit"
                            class="text-sm text-gray-600 hover:text-brand"
                        >
                            Выйти
                        </button>
                    </form>
                </div>

                <div
                    v-if="!data.member.verified"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-site bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    <span>
                        Подтвердите e-mail по ссылке из письма — после этого
                        здесь появятся ваши заявки.
                    </span>
                    <form
                        :action="data.actions.resend ?? undefined"
                        method="post"
                    >
                        <input type="hidden" name="_token" :value="data.csrf" />
                        <button type="submit" class="underline">
                            Отправить письмо ещё раз
                        </button>
                    </form>
                </div>

                <section
                    v-for="group in data.submissions ?? []"
                    :key="group.label"
                    class="flex flex-col gap-3"
                >
                    <h2 class="font-heading text-xl font-semibold text-ink">
                        {{ group.label }}
                    </h2>
                    <p v-if="!group.items.length" class="text-sm text-gray-500">
                        Пока ничего нет.
                    </p>
                    <article
                        v-for="(item, index) in group.items"
                        :key="index"
                        class="rounded-site border border-gray-200 p-4"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <span
                                class="flex items-center gap-2 font-medium text-ink"
                            >
                                {{ item.title }}
                                <span
                                    v-if="item.status"
                                    class="rounded-full bg-brand/10 px-2 py-0.5 text-xs text-brand"
                                    >{{ item.status }}</span
                                >
                            </span>
                            <span class="text-sm text-gray-500">{{
                                date(item.date)
                            }}</span>
                        </div>
                        <dl
                            v-if="item.fields.length"
                            class="mt-2 grid gap-x-4 gap-y-1 text-sm @3xl:grid-cols-[12rem_1fr]"
                        >
                            <template
                                v-for="field in item.fields"
                                :key="field.label"
                            >
                                <dt class="text-gray-500">{{ field.label }}</dt>
                                <dd class="whitespace-pre-line text-ink">
                                    {{ field.value }}
                                </dd>
                            </template>
                        </dl>
                    </article>
                </section>

                <details class="rounded-site border border-gray-200 p-4">
                    <summary class="cursor-pointer font-medium text-ink">
                        Настройки профиля
                    </summary>
                    <form
                        :action="data.actions.profile ?? undefined"
                        method="post"
                        class="mt-4 flex flex-col gap-4"
                    >
                        <input type="hidden" name="_token" :value="data.csrf" />
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-gray-700">Имя</span>
                            <input
                                type="text"
                                name="name"
                                :value="data.old.name ?? data.member.name"
                                required
                                :class="input"
                            />
                            <span
                                v-if="errors.name"
                                class="text-xs text-red-600"
                            >
                                {{ errors.name }}
                            </span>
                        </label>
                        <label
                            v-for="field in data.profile_fields ?? []"
                            :key="field.name"
                            class="flex flex-col gap-1 text-sm"
                        >
                            <span class="font-medium text-gray-700">
                                {{ field.label }}
                                <span v-if="field.required" class="text-red-500"
                                    >*</span
                                >
                            </span>
                            <input
                                :type="field.type === 'phone' ? 'tel' : 'text'"
                                :name="`profile[${field.name}]`"
                                :value="
                                    data.old.profile?.[field.name] ??
                                    data.member.profile?.[field.name]
                                "
                                :required="field.required"
                                :autocomplete="
                                    field.type === 'phone' ? 'tel' : 'on'
                                "
                                :class="input"
                            />
                            <span
                                v-if="errors[`profile.${field.name}`]"
                                class="text-xs text-red-600"
                            >
                                {{ errors[`profile.${field.name}`] }}
                            </span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-gray-700"
                                >Текущий пароль</span
                            >
                            <input
                                type="password"
                                name="current_password"
                                autocomplete="current-password"
                                :class="input"
                            />
                            <span
                                v-if="errors.current_password"
                                class="text-xs text-red-600"
                            >
                                {{ errors.current_password }}
                            </span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-gray-700"
                                >Новый пароль</span
                            >
                            <input
                                type="password"
                                name="password"
                                minlength="8"
                                autocomplete="new-password"
                                :class="input"
                            />
                            <span
                                v-if="errors.password"
                                class="text-xs text-red-600"
                            >
                                {{ errors.password }}
                            </span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-gray-700"
                                >Новый пароль ещё раз</span
                            >
                            <input
                                type="password"
                                name="password_confirmation"
                                autocomplete="new-password"
                                :class="input"
                            />
                        </label>
                        <button
                            type="submit"
                            :class="button"
                            class="self-start"
                        >
                            Сохранить
                        </button>
                    </form>
                </details>
            </template>
        </div>
    </section>
</template>
