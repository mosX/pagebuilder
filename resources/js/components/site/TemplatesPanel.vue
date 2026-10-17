<script setup lang="ts">
import { Delete, DocumentCopy } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import customTemplates from '@/routes/api/custom-templates';
import { useCollectionsStore } from '@/stores/collections';
import type { MessageResponse } from '@/types';

type Template = {
    id: number;
    name: string;
    description: string | null;
    is_public: boolean;
    owner: string | null;
    pages: number;
    collections: number;
    records: number;
    files: number;
    created_at: string | null;
};

/**
 * Saving the company site as a template for new companies, and the user's saved templates.
 */
defineProps<{ canEdit: boolean }>();

const collections = useCollectionsStore();
const templates = ref<Template[]>([]);
const canPublish = ref(false);
const loading = ref(true);
const dialog = ref(false);

const form = useForm({
    name: '',
    description: '',
    record_collections: [] as string[],
});

// Pages are always included; records are copied only from collections chosen here.
const dataCollections = computed(() =>
    collections.items.filter((c) => !c.kind),
);

async function load() {
    loading.value = true;

    try {
        const result = await send<{
            templates: Template[];
            can_publish: boolean;
        }>(customTemplates.index());
        templates.value = result.templates;
        canPublish.value = result.can_publish;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openDialog() {
    form.reset();
    // Site sections (articles, products) are content worth copying; form submissions are not.
    form.record_collections = dataCollections.value
        .filter((c) => c.site.path)
        .map((c) => c.name);
    dialog.value = true;
}

async function save() {
    const result = await form.submit<MessageResponse>(customTemplates.store());

    if (result) {
        dialog.value = false;
        ElMessage.success(result.message);
        await load();
    }
}

async function update(template: Template, changes: Partial<Template>) {
    const result = await send<MessageResponse>(
        customTemplates.update(template.id),
        {
            name: template.name,
            description: template.description,
            ...changes,
        },
    );
    ElMessage.success(result.message);
    await load();
}

async function remove(template: Template) {
    const result = await send<MessageResponse>(
        customTemplates.destroy(template.id),
    );
    ElMessage.success(result.message);
    await load();
}
</script>

<template>
    <div v-loading="loading" class="flex flex-col gap-4">
        <el-card shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p
                    class="max-w-2xl text-sm text-[var(--el-text-color-secondary)]"
                >
                    Сохраните этот сайт как шаблон: страницы, шапку и подвал,
                    оформление, меню, секции, коллекции и файлы. Новые компании
                    можно будет создавать из него — например, чтобы быстро
                    запускать типовые сайты для клиентов.
                </p>
                <el-button
                    v-if="canEdit"
                    type="primary"
                    :icon="DocumentCopy"
                    @click="openDialog"
                >
                    Сохранить сайт как шаблон
                </el-button>
            </div>
        </el-card>

        <el-card shadow="never" header="Мои шаблоны" body-class="!p-0">
            <el-table :data="templates" empty-text="Шаблонов пока нет">
                <el-table-column label="Шаблон" min-width="240">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.name }}</div>
                        <div
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ row.pages }} стр. ·
                            {{ row.collections }} коллекций ·
                            {{ row.records }} записей · {{ row.files }} файлов
                            <template v-if="canPublish && row.owner">
                                · {{ row.owner }}</template
                            >
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="Доступен" width="200">
                    <template #default="{ row }">
                        <el-switch
                            v-if="canPublish"
                            :model-value="row.is_public"
                            active-text="всем"
                            inactive-text="только мне"
                            @change="
                                (value: boolean) =>
                                    update(row, { is_public: value })
                            "
                        />
                        <el-tag
                            v-else
                            :type="row.is_public ? 'success' : 'info'"
                        >
                            {{ row.is_public ? 'всем' : 'только мне' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column width="80" align="right">
                    <template #default="{ row }">
                        <el-popconfirm
                            :title="`Удалить шаблон «${row.name}»? Созданные из него сайты не изменятся.`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="280"
                            @confirm="remove(row)"
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

        <el-dialog
            v-model="dialog"
            title="Сохранить сайт как шаблон"
            width="520"
        >
            <el-form label-position="top" @submit.prevent="save">
                <el-form-item label="Название" :error="form.errors.name">
                    <el-input
                        v-model="form.name"
                        placeholder="Например, «Сайт стоматологии»"
                    />
                </el-form-item>
                <el-form-item label="Описание" :error="form.errors.description">
                    <el-input
                        v-model="form.description"
                        type="textarea"
                        :rows="2"
                    />
                </el-form-item>
                <el-form-item
                    label="Копировать записи из коллекций"
                    :error="form.errors.record_collections"
                >
                    <el-checkbox-group
                        v-model="form.record_collections"
                        class="flex flex-col"
                    >
                        <el-checkbox
                            v-for="collection in dataCollections"
                            :key="collection.name"
                            :value="collection.name"
                        >
                            {{ collection.label }}
                        </el-checkbox>
                    </el-checkbox-group>
                    <div
                        class="mt-1 text-xs text-[var(--el-text-color-secondary)]"
                    >
                        Структура всех коллекций сохраняется всегда. Не
                        отмечайте коллекции с заявками и личными данными
                        посетителей.
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="save"
                >
                    Сохранить
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
