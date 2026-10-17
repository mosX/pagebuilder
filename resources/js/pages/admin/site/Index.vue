<script setup lang="ts">
import { Delete, Edit, Picture, Plus, View } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import MediaPicker from '@/components/media/MediaPicker.vue';
import MenuEditor from '@/components/site/MenuEditor.vue';
import type { MenuItem } from '@/components/site/MenuEditor.vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import siteSettings from '@/routes/api/site-settings';
import { useSessionStore } from '@/stores/session';
import { useSiteThemeStore } from '@/stores/site-theme';

type Theme = {
    primary: string;
    text: string;
    background: string;
    heading_font: string;
    body_font: string;
    radius: string;
};

type Languages = { default: string; extra: string[] };

type Account = {
    enabled: boolean;
    registration: boolean;
    collections: { collection: string; email_field: string }[];
};

type AccountCollection = {
    name: string;
    label: string;
    email_fields: { name: string; label: string; type: string }[];
};

type Settings = {
    theme: Theme;
    menu: MenuItem[];
    languages: Languages;
    account: Account;
    locales: { value: string; label: string }[];
    account_collections: AccountCollection[];
    logo: string | null;
    favicon: string | null;
    site_url: string;
    fonts: { value: string; label: string }[];
    pages: { title: string; slug: string; is_published: boolean | number }[];
};

const session = useSessionStore();
const siteTheme = useSiteThemeStore();
const router = useRouter();
const canEdit = computed(() => session.can('company.update'));

const loading = ref(true);
const tab = ref('theme');
const fonts = ref<Settings['fonts']>([]);
const pages = ref<Settings['pages']>([]);
const siteUrl = ref('');
const locales = ref<Settings['locales']>([]);
const accountCollections = ref<AccountCollection[]>([]);
const picker = ref<'logo' | 'favicon' | null>(null);

const form = useForm({
    theme: {
        primary: '#2563eb',
        text: '#111827',
        background: '#ffffff',
        heading_font: 'inter',
        body_font: 'inter',
        radius: 'medium',
    } as Theme,
    menu: [] as MenuItem[],
    logo: null as string | null,
    favicon: null as string | null,
    languages: { default: 'ru', extra: [] } as Languages,
    account: { enabled: false, registration: true, collections: [] } as Account,
});

/** Additional languages with names, for menu labels. */
const extraLanguages = computed(() =>
    locales.value.filter((l) => form.languages.extra.includes(l.value)),
);

function emailFields(name: string) {
    return (
        accountCollections.value.find((c) => c.name === name)?.email_fields ??
        []
    );
}

function addAccountCollection() {
    const collection = accountCollections.value[0];

    form.account.collections = [
        ...form.account.collections,
        {
            collection: collection?.name ?? '',
            email_field:
                collection?.email_fields.find((f) => f.type === 'email')
                    ?.name ??
                collection?.email_fields[0]?.name ??
                '',
        },
    ];
}

const radii = [
    { value: 'none', label: 'Без скруглений', css: '0' },
    { value: 'small', label: 'Малое', css: '0.25rem' },
    { value: 'medium', label: 'Среднее', css: '0.5rem' },
    { value: 'large', label: 'Большое', css: '1rem' },
];

/** Live preview of the theme before saving. */
const previewStyle = computed(() => {
    const font = (key: string) =>
        `'${fonts.value.find((f) => f.value === key)?.label ?? 'Inter'}', sans-serif`;

    return {
        '--site-primary': form.theme.primary,
        '--site-text': form.theme.text,
        '--site-background': form.theme.background,
        '--site-radius':
            radii.find((r) => r.value === form.theme.radius)?.css ?? '0.5rem',
        '--site-font-heading': font(form.theme.heading_font),
        '--site-font-body': font(form.theme.body_font),
    };
});

async function load() {
    const settings = await send<Settings>(siteSettings.show());

    form.theme = { ...settings.theme };
    form.menu = settings.menu;
    form.logo = settings.logo;
    form.favicon = settings.favicon;
    form.languages = { ...settings.languages };
    form.account = structuredClone(settings.account);
    form.defaults();

    locales.value = settings.locales;
    accountCollections.value = settings.account_collections;

    fonts.value = settings.fonts;
    pages.value = settings.pages;
    siteUrl.value = settings.site_url;
    // Fonts of every option, so the preview shows them.
    siteTheme.useFonts(
        `https://fonts.bunny.net/css?family=${settings.fonts.map((f) => `${f.value}:400,700`).join('|')}&display=swap`,
    );
}

onMounted(async () => {
    try {
        await load();
    } finally {
        loading.value = false;
    }
});

async function save() {
    const result = await form.submit<{ message: string }>(
        siteSettings.update(),
    );

    if (result) {
        form.defaults();
        siteTheme.reset();
        ElMessage.success(result.message);
    }
}

function pick(url: string) {
    if (picker.value) {
        form[picker.value] = url;
    }
}
</script>

<template>
    <div v-loading="loading">
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Сайт</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Оформление, меню, шапка и подвал сайта компании.
                </p>
            </div>
            <div class="flex gap-2">
                <el-button :icon="View" tag="a" :href="siteUrl" target="_blank">
                    Открыть сайт
                </el-button>
                <el-button
                    v-if="canEdit"
                    type="primary"
                    :loading="form.processing"
                    :disabled="!form.isDirty"
                    @click="save"
                >
                    Сохранить
                </el-button>
            </div>
        </div>

        <el-tabs v-model="tab">
            <el-tab-pane label="Тема" name="theme">
                <div class="grid gap-4 lg:grid-cols-[1fr_1fr]">
                    <el-card shadow="never">
                        <el-form label-position="top" :disabled="!canEdit">
                            <div class="grid grid-cols-3 gap-4">
                                <el-form-item label="Основной цвет">
                                    <el-color-picker
                                        v-model="form.theme.primary"
                                    />
                                </el-form-item>
                                <el-form-item label="Цвет текста">
                                    <el-color-picker
                                        v-model="form.theme.text"
                                    />
                                </el-form-item>
                                <el-form-item label="Фон">
                                    <el-color-picker
                                        v-model="form.theme.background"
                                    />
                                </el-form-item>
                            </div>
                            <el-form-item label="Шрифт заголовков">
                                <el-select
                                    v-model="form.theme.heading_font"
                                    filterable
                                >
                                    <el-option
                                        v-for="font in fonts"
                                        :key="font.value"
                                        :label="font.label"
                                        :value="font.value"
                                        :style="{
                                            fontFamily: `'${font.label}'`,
                                        }"
                                    />
                                </el-select>
                            </el-form-item>
                            <el-form-item label="Шрифт текста">
                                <el-select
                                    v-model="form.theme.body_font"
                                    filterable
                                >
                                    <el-option
                                        v-for="font in fonts"
                                        :key="font.value"
                                        :label="font.label"
                                        :value="font.value"
                                        :style="{
                                            fontFamily: `'${font.label}'`,
                                        }"
                                    />
                                </el-select>
                            </el-form-item>
                            <el-form-item label="Скругления">
                                <el-radio-group v-model="form.theme.radius">
                                    <el-radio-button
                                        v-for="radius in radii"
                                        :key="radius.value"
                                        :value="radius.value"
                                    >
                                        {{ radius.label }}
                                    </el-radio-button>
                                </el-radio-group>
                            </el-form-item>
                            <div class="grid grid-cols-2 gap-4">
                                <el-form-item
                                    v-for="kind in ['logo', 'favicon'] as const"
                                    :key="kind"
                                    :label="
                                        kind === 'logo' ? 'Логотип' : 'Фавикон'
                                    "
                                >
                                    <div class="flex items-center gap-2">
                                        <img
                                            v-if="form[kind]"
                                            :src="form[kind] ?? ''"
                                            alt=""
                                            class="h-10 max-w-28 rounded bg-gray-100 object-contain"
                                        />
                                        <el-button
                                            size="small"
                                            :icon="Picture"
                                            @click="picker = kind"
                                        >
                                            Выбрать
                                        </el-button>
                                        <el-button
                                            v-if="form[kind]"
                                            size="small"
                                            text
                                            @click="form[kind] = null"
                                        >
                                            Убрать
                                        </el-button>
                                    </div>
                                </el-form-item>
                            </div>
                        </el-form>
                    </el-card>

                    <el-card shadow="never" header="Превью">
                        <div
                            class="flex flex-col gap-4 rounded border border-gray-200 bg-surface p-6 font-body text-ink"
                            :style="previewStyle"
                        >
                            <h2 class="font-heading text-3xl font-bold">
                                Заголовок страницы
                            </h2>
                            <p>
                                Так будет выглядеть обычный текст на сайте.
                                Ссылка —
                                <a
                                    href="#"
                                    class="text-brand underline"
                                    @click.prevent
                                    >подробнее</a
                                >.
                            </p>
                            <div class="flex gap-3">
                                <span
                                    class="rounded-site bg-brand px-5 py-2.5 font-medium text-white"
                                >
                                    Основная кнопка
                                </span>
                                <span
                                    class="rounded-site border border-brand px-5 py-2.5 font-medium text-brand"
                                >
                                    Контурная
                                </span>
                            </div>
                            <div
                                class="rounded-site border border-gray-200 p-4"
                            >
                                <div class="font-heading font-semibold">
                                    Карточка
                                </div>
                                <div class="text-sm text-gray-500">
                                    Скругления и цвета из темы
                                </div>
                            </div>
                        </div>
                    </el-card>
                </div>
            </el-tab-pane>

            <el-tab-pane label="Меню" name="menu">
                <el-card shadow="never">
                    <p
                        class="mb-4 text-sm text-[var(--el-text-color-secondary)]"
                    >
                        Пока меню пустое, на сайте показываются все
                        опубликованные страницы. Блок «Меню» в шапке и подвале
                        берёт пункты отсюда.
                    </p>
                    <MenuEditor
                        v-model="form.menu"
                        :pages="pages"
                        :disabled="!canEdit"
                        :languages="extraLanguages"
                    />
                </el-card>
            </el-tab-pane>

            <el-tab-pane label="Языки" name="languages">
                <el-card shadow="never">
                    <el-form
                        label-position="top"
                        :disabled="!canEdit"
                        class="max-w-xl"
                    >
                        <el-form-item label="Основной язык">
                            <el-select v-model="form.languages.default">
                                <el-option
                                    v-for="locale in locales"
                                    :key="locale.value"
                                    :label="locale.label"
                                    :value="locale.value"
                                />
                            </el-select>
                        </el-form-item>
                        <el-form-item label="Дополнительные языки">
                            <el-select
                                v-model="form.languages.extra"
                                multiple
                                placeholder="Сайт на одном языке"
                            >
                                <el-option
                                    v-for="locale in locales.filter(
                                        (l) =>
                                            l.value !== form.languages.default,
                                    )"
                                    :key="locale.value"
                                    :label="locale.label"
                                    :value="locale.value"
                                />
                            </el-select>
                        </el-form-item>
                    </el-form>
                    <div
                        class="flex max-w-xl flex-col gap-2 text-sm text-[var(--el-text-color-secondary)]"
                    >
                        <p>
                            Страницы на дополнительных языках открываются по
                            адресам с префиксом: <code>/en/about</code>. Адреса
                            (slug) у всех языков общие.
                        </p>
                        <p>
                            Переводы заполняются в карточке записи (вкладки
                            языков) и в конструкторе блоков (переключатель
                            языка). Что не переведено, показывается на основном
                            языке. Названия пунктов меню переводятся во вкладке
                            «Меню», переключатель языков — блок «Языки».
                        </p>
                    </div>
                </el-card>
            </el-tab-pane>

            <el-tab-pane label="Кабинет" name="account">
                <el-card shadow="never">
                    <el-form
                        label-position="top"
                        :disabled="!canEdit"
                        class="max-w-2xl"
                    >
                        <el-form-item>
                            <el-switch
                                v-model="form.account.enabled"
                                active-text="Личный кабинет посетителей"
                            />
                        </el-form-item>
                        <template v-if="form.account.enabled">
                            <el-form-item>
                                <el-switch
                                    v-model="form.account.registration"
                                    active-text="Посетители могут регистрироваться сами"
                                />
                            </el-form-item>
                            <el-form-item label="«Мои заявки» в кабинете">
                                <div class="flex w-full flex-col gap-2">
                                    <div
                                        v-for="(item, index) in form.account
                                            .collections"
                                        :key="index"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <el-select
                                            v-model="item.collection"
                                            class="!w-56"
                                            @change="
                                                item.email_field =
                                                    emailFields(
                                                        item.collection,
                                                    )[0]?.name ?? ''
                                            "
                                        >
                                            <el-option
                                                v-for="collection in accountCollections"
                                                :key="collection.name"
                                                :label="collection.label"
                                                :value="collection.name"
                                            />
                                        </el-select>
                                        <span class="text-sm">по полю</span>
                                        <el-select
                                            v-model="item.email_field"
                                            class="!w-48"
                                        >
                                            <el-option
                                                v-for="field in emailFields(
                                                    item.collection,
                                                )"
                                                :key="field.name"
                                                :label="field.label"
                                                :value="field.name"
                                            />
                                        </el-select>
                                        <el-button
                                            :icon="Delete"
                                            link
                                            type="danger"
                                            @click="
                                                form.account.collections =
                                                    form.account.collections.filter(
                                                        (_, i) => i !== index,
                                                    )
                                            "
                                        />
                                    </div>
                                    <el-button
                                        :icon="Plus"
                                        size="small"
                                        class="self-start"
                                        :disabled="
                                            !accountCollections.length ||
                                            form.account.collections.length >= 5
                                        "
                                        @click="addAccountCollection"
                                    >
                                        Коллекция
                                    </el-button>
                                </div>
                            </el-form-item>
                        </template>
                    </el-form>
                    <div
                        class="flex max-w-2xl flex-col gap-2 text-sm text-[var(--el-text-color-secondary)]"
                    >
                        <p>
                            Вход — <code>/account/login</code>, кабинет —
                            <code>/account</code>. В шапку добавьте блок «Вход /
                            кабинет». Страницы с отметкой «Только для
                            зарегистрированных» видны после входа.
                        </p>
                        <p>
                            В кабинете посетитель видит записи выбранных
                            коллекций, где e-mail совпадает с его адресом, —
                            после подтверждения e-mail по ссылке из письма.
                            Формы на сайте подставляют имя и e-mail вошедшего
                            посетителя.
                        </p>
                    </div>
                </el-card>
            </el-tab-pane>

            <el-tab-pane label="Шапка и подвал" name="layout">
                <div class="grid gap-4 md:grid-cols-2">
                    <el-card
                        v-for="part in ['header', 'footer'] as const"
                        :key="part"
                        shadow="never"
                    >
                        <div class="flex flex-col gap-3">
                            <div class="font-medium">
                                {{ part === 'header' ? 'Шапка' : 'Подвал' }}
                            </div>
                            <p
                                class="text-sm text-[var(--el-text-color-secondary)]"
                            >
                                Собирается в конструкторе из любых блоков. Блоки
                                «Логотип» и «Меню» в группе «Сайт» берут данные
                                из настроек.
                            </p>
                            <el-button
                                :icon="Edit"
                                class="self-start"
                                :disabled="!session.can('content.manage')"
                                @click="
                                    router.push({
                                        name: 'site.layout',
                                        params: { part },
                                    })
                                "
                            >
                                Редактировать
                            </el-button>
                        </div>
                    </el-card>
                </div>
            </el-tab-pane>
        </el-tabs>

        <MediaPicker
            :model-value="picker !== null"
            :selected-url="picker ? form[picker] : null"
            @update:model-value="(open: boolean) => !open && (picker = null)"
            @pick="(item) => pick(item.url)"
        />
    </div>
</template>
