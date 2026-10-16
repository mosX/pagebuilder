<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Delete,
    Document,
    Edit,
    Plus,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FieldDialog from '@/components/collections/FieldDialog.vue';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import collectionsApi from '@/routes/api/collections';
import fieldsApi from '@/routes/api/collections/fields';
import { useCollectionsStore } from '@/stores/collections';
import type {
    CollectionField,
    CollectionSchema,
    FieldTypeOption,
    MessageResponse,
} from '@/types';

const DISPLAYABLE = ['string', 'email', 'url', 'integer', 'select', 'date'];

const route = useRoute();
const router = useRouter();
const collections = useCollectionsStore();
const name = String(route.params.name);

const collection = ref<CollectionSchema | null>(null);
const fieldTypes = ref<FieldTypeOption[]>([]);
const loading = ref(true);

const settings = useForm({
    label: '',
    description: '',
    display_field: null as string | null,
});

const displayOptions = computed(
    () =>
        collection.value?.fields.filter((f) => DISPLAYABLE.includes(f.type)) ??
        [],
);

async function load() {
    loading.value = true;

    try {
        const result = await send<{
            collection: CollectionSchema;
            field_types: FieldTypeOption[];
        }>(collectionsApi.show(name));

        collection.value = result.collection;
        fieldTypes.value = result.field_types;
        settings.label = result.collection.label;
        settings.description = result.collection.description ?? '';
        settings.display_field = result.collection.display_field;
        settings.defaults();

        siteForm.enabled = Boolean(result.collection.site.path);
        siteForm.site_path =
            result.collection.site.path ??
            result.collection.name.replace(/_/g, '-');
        siteForm.site_slug_field = result.collection.site.slug_field ?? 'id';
        siteForm.site_published_field = result.collection.site.published_field;
        siteForm.defaults();
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await load();

    if (!collections.loaded) {
        await collections.load();
    }
});

async function saveSettings() {
    const result = await settings.submit<MessageResponse>(
        collectionsApi.update(name),
    );

    if (result) {
        settings.defaults();
        ElMessage.success(result.message);
        await collections.load();
    }
}

// Publication of the collection as a section of the company site.
const siteForm = useForm({
    enabled: false,
    site_path: '',
    site_slug_field: 'id' as string,
    site_published_field: null as string | null,
});

// Record URLs need a unique value: the id or a unique text/number field.
const slugFieldOptions = computed(() => [
    { name: 'id', label: 'ID записи' },
    ...(collection.value?.fields ?? []).filter(
        (f) => f.is_unique && (f.type === 'string' || f.type === 'integer'),
    ),
]);

const booleanFields = computed(
    () => collection.value?.fields.filter((f) => f.type === 'boolean') ?? [],
);

async function saveSite() {
    const payload = siteForm.enabled
        ? siteForm.data()
        : {
              site_path: null,
              site_slug_field: null,
              site_published_field: null,
          };

    try {
        const result = await send<
            MessageResponse & { collection: CollectionSchema }
        >(collectionsApi.site(name), payload);
        collection.value = {
            ...result.collection,
            fields: collection.value?.fields ?? [],
        };
        siteForm.defaults();
        siteForm.clearErrors();
        ElMessage.success(result.message);
    } catch (error) {
        if (isValidationError(error)) {
            siteForm.errors = Object.fromEntries(
                Object.entries(error.response?.data.errors ?? {}).map(
                    ([k, v]) => [k, v[0]],
                ),
            );
        }
    }
}

const dialogOpen = ref(false);
const editingField = ref<CollectionField | null>(null);

function openField(field: CollectionField | null) {
    editingField.value = field;
    dialogOpen.value = true;
}

async function onFieldSaved() {
    await Promise.all([load(), collections.load()]);
}

async function removeField(field: CollectionField) {
    try {
        const result = await send<MessageResponse>(
            fieldsApi.destroy({ collection: name, field: field.id }),
        );
        ElMessage.success(result.message);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(error.response?.data.message ?? '');
        }
    }

    await onFieldSaved();
}

async function move(index: number, delta: number) {
    const list = [...(collection.value?.fields ?? [])];
    const target = index + delta;

    if (!collection.value || target < 0 || target >= list.length) {
        return;
    }

    [list[index], list[target]] = [list[target], list[index]];
    collection.value.fields = list;

    await send(fieldsApi.reorder(name), { ids: list.map((f) => f.id) });
}

function flags(field: CollectionField) {
    return [
        field.is_system ? 'системное' : null,
        field.is_required ? 'обязательное' : null,
        field.is_unique ? 'уникальное' : null,
    ].filter(Boolean);
}
</script>

<template>
    <div v-loading="loading">
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <el-breadcrumb class="mb-2">
                    <el-breadcrumb-item :to="{ name: 'collections' }">
                        Коллекции
                    </el-breadcrumb-item>
                    <el-breadcrumb-item>{{
                        collection?.label
                    }}</el-breadcrumb-item>
                </el-breadcrumb>
                <h1 class="text-xl font-semibold">
                    Структура: {{ collection?.label }}
                </h1>
                <code class="text-xs text-[var(--el-text-color-secondary)]">
                    {{ collection?.table_name }}
                </code>
            </div>
            <div class="flex gap-2">
                <el-button
                    :icon="Document"
                    @click="router.push({ name: 'records', params: { name } })"
                >
                    Записи
                </el-button>
                <el-button type="primary" :icon="Plus" @click="openField(null)">
                    Добавить поле
                </el-button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_340px]">
            <el-card shadow="never" body-class="!p-0">
                <el-table
                    v-if="collection"
                    :data="collection.fields"
                    row-key="id"
                    empty-text="Полей пока нет — добавьте первое"
                >
                    <el-table-column width="80">
                        <template #default="{ $index }">
                            <el-button
                                :icon="ArrowUp"
                                link
                                :disabled="$index === 0"
                                @click="move($index, -1)"
                            />
                            <el-button
                                :icon="ArrowDown"
                                link
                                :disabled="
                                    $index === collection.fields.length - 1
                                "
                                @click="move($index, 1)"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="Поле" min-width="180">
                        <template #default="{ row }">
                            <div class="font-medium">{{ row.label }}</div>
                            <code class="text-xs">{{ row.name }}</code>
                        </template>
                    </el-table-column>
                    <el-table-column label="Тип" min-width="200">
                        <template #default="{ row }">
                            {{ row.type_label }}
                            <span v-if="row.related_collection">
                                → {{ row.related_collection.label }}
                            </span>
                            <div
                                v-if="row.options.choices?.length"
                                class="text-xs text-[var(--el-text-color-secondary)]"
                            >
                                {{ row.options.choices.join(', ') }}
                            </div>
                        </template>
                    </el-table-column>
                    <el-table-column label="Свойства" min-width="150">
                        <template #default="{ row }">
                            <el-tag
                                v-for="flag in flags(row)"
                                :key="String(flag)"
                                size="small"
                                type="info"
                                class="mr-1"
                            >
                                {{ flag }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column width="100" align="right">
                        <template #default="{ row }">
                            <el-button
                                :icon="Edit"
                                circle
                                size="small"
                                @click="openField(row)"
                            />
                            <el-popconfirm
                                v-if="!row.is_system"
                                :title="`Удалить поле «${row.label}» вместе с данными?`"
                                confirm-button-text="Удалить"
                                cancel-button-text="Отмена"
                                width="280"
                                @confirm="removeField(row)"
                            >
                                <template #reference>
                                    <el-button
                                        type="danger"
                                        :icon="Delete"
                                        circle
                                        plain
                                        size="small"
                                    />
                                </template>
                            </el-popconfirm>
                        </template>
                    </el-table-column>
                </el-table>
            </el-card>

            <div class="flex flex-col gap-4">
                <el-card shadow="never" header="Настройки коллекции">
                    <el-form
                        label-position="top"
                        @submit.prevent="saveSettings"
                    >
                        <el-form-item
                            label="Название"
                            :error="settings.errors.label"
                        >
                            <el-input v-model="settings.label" />
                        </el-form-item>
                        <el-form-item
                            label="Описание"
                            :error="settings.errors.description"
                        >
                            <el-input
                                v-model="settings.description"
                                type="textarea"
                            />
                        </el-form-item>
                        <el-form-item
                            label="Заголовок записи"
                            :error="settings.errors.display_field"
                        >
                            <el-select
                                v-model="settings.display_field"
                                clearable
                                placeholder="Первое текстовое поле"
                            >
                                <el-option
                                    v-for="f in displayOptions"
                                    :key="f.name"
                                    :label="f.label"
                                    :value="f.name"
                                />
                            </el-select>
                            <div
                                class="text-xs text-[var(--el-text-color-secondary)]"
                            >
                                Как записи этой коллекции показываются в связях.
                            </div>
                        </el-form-item>
                        <el-button
                            type="primary"
                            native-type="submit"
                            :loading="settings.processing"
                            :disabled="!settings.isDirty"
                        >
                            Сохранить
                        </el-button>
                    </el-form>
                </el-card>

                <el-card
                    v-if="!collection?.kind"
                    shadow="never"
                    header="Раздел сайта"
                >
                    <el-form label-position="top" @submit.prevent="saveSite">
                        <el-form-item>
                            <el-switch
                                v-model="siteForm.enabled"
                                active-text="Страница для каждой записи"
                            />
                        </el-form-item>
                        <template v-if="siteForm.enabled">
                            <el-form-item
                                label="Путь"
                                :error="siteForm.errors.site_path"
                            >
                                <el-input v-model="siteForm.site_path">
                                    <template #prepend>/</template>
                                    <template #append>/…</template>
                                </el-input>
                            </el-form-item>
                            <el-form-item
                                label="Адрес записи из поля"
                                :error="siteForm.errors.site_slug_field"
                            >
                                <el-select v-model="siteForm.site_slug_field">
                                    <el-option
                                        v-for="f in slugFieldOptions"
                                        :key="f.name"
                                        :label="f.label"
                                        :value="f.name"
                                    />
                                </el-select>
                                <div
                                    class="text-xs text-[var(--el-text-color-secondary)]"
                                >
                                    Только ID или уникальное текстовое/числовое
                                    поле.
                                </div>
                            </el-form-item>
                            <el-form-item
                                label="Показывать только если включено поле"
                                :error="siteForm.errors.site_published_field"
                            >
                                <el-select
                                    v-model="siteForm.site_published_field"
                                    clearable
                                    placeholder="Показывать все записи"
                                >
                                    <el-option
                                        v-for="f in booleanFields"
                                        :key="f.name"
                                        :label="f.label"
                                        :value="f.name"
                                    />
                                </el-select>
                            </el-form-item>
                        </template>
                        <div class="flex flex-wrap gap-2">
                            <el-button
                                type="primary"
                                native-type="submit"
                                :disabled="!siteForm.isDirty"
                            >
                                Сохранить
                            </el-button>
                            <el-button
                                v-if="collection?.site.path"
                                @click="
                                    router.push({
                                        name: 'collections.template',
                                        params: { name },
                                    })
                                "
                            >
                                Шаблон страницы
                            </el-button>
                        </div>
                        <div
                            v-if="collection?.site.url"
                            class="mt-3 truncate text-xs text-[var(--el-text-color-secondary)]"
                        >
                            Адреса записей: {{ collection.site.url }}/…
                        </div>
                    </el-form>
                </el-card>
            </div>
        </div>

        <FieldDialog
            v-if="collection"
            v-model="dialogOpen"
            :collection="collection"
            :field-types="fieldTypes"
            :collections="collections.items"
            :field="editingField"
            @saved="onFieldSaved"
        />
    </div>
</template>
