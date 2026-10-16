<script setup lang="ts">
import { Delete, Document, Plus, SetUp } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import { toIdentifier } from '@/lib/identifier';
import collectionsApi from '@/routes/api/collections';
import { useCollectionsStore } from '@/stores/collections';
import { useSessionStore } from '@/stores/session';
import type { CollectionSchema, MessageResponse } from '@/types';

const session = useSessionStore();
const collections = useCollectionsStore();
const router = useRouter();
const loading = ref(true);

onMounted(async () => {
    try {
        await collections.load();
    } finally {
        loading.value = false;
    }
});

const dialogOpen = ref(false);
const nameTouched = ref(false);
const form = useForm({
    label: '',
    name: '',
    description: '',
});

// Suggest the system name from the label until the user edits it.
watch(
    () => form.label,
    (label) => {
        if (!nameTouched.value) {
            form.name = toIdentifier(label);
        }
    },
);

function openCreate() {
    form.reset();
    form.clearErrors();
    nameTouched.value = false;
    dialogOpen.value = true;
}

async function create() {
    const result = await form.submit<{
        data: CollectionSchema;
        message: string;
    }>(collectionsApi.store());

    if (result) {
        dialogOpen.value = false;
        ElMessage.success(result.message);
        await collections.load();
        await router.push({
            name: 'collections.schema',
            params: { name: result.data.name },
        });
    }
}

async function remove(collection: CollectionSchema) {
    try {
        const result = await send<MessageResponse>(
            collectionsApi.destroy(collection.name),
        );
        ElMessage.success(result.message);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(error.response?.data.message ?? '');
        }
    }

    await collections.load();
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Коллекции</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Типы данных компании. У каждой коллекции своя таблица в базе
                    данных.
                </p>
            </div>
            <el-button
                v-if="session.can('schema.manage')"
                type="primary"
                :icon="Plus"
                @click="openCreate"
            >
                Создать коллекцию
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="collections.items"
                row-key="id"
                empty-text="Коллекций пока нет"
            >
                <el-table-column label="Название" min-width="200">
                    <template #default="{ row }">
                        <router-link
                            :to="{
                                name: 'records',
                                params: { name: row.name },
                            }"
                            class="font-medium text-[var(--el-color-primary)]"
                        >
                            {{ row.label }}
                        </router-link>
                        <el-tag
                            v-if="row.kind"
                            size="small"
                            type="info"
                            class="ml-2"
                        >
                            системная
                        </el-tag>
                        <div
                            v-if="row.description"
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ row.description }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="Таблица" min-width="180">
                    <template #default="{ row }">
                        <code class="text-xs">{{ row.table_name }}</code>
                    </template>
                </el-table-column>
                <el-table-column label="Поля" width="100">
                    <template #default="{ row }">{{
                        row.fields.length
                    }}</template>
                </el-table-column>
                <el-table-column
                    prop="records_count"
                    label="Записей"
                    width="110"
                />
                <el-table-column width="150" align="right">
                    <template #default="{ row }">
                        <el-tooltip content="Записи">
                            <el-button
                                :icon="Document"
                                circle
                                size="small"
                                @click="
                                    router.push({
                                        name: 'records',
                                        params: { name: row.name },
                                    })
                                "
                            />
                        </el-tooltip>
                        <template v-if="session.can('schema.manage')">
                            <el-tooltip content="Структура">
                                <el-button
                                    :icon="SetUp"
                                    circle
                                    size="small"
                                    @click="
                                        router.push({
                                            name: 'collections.schema',
                                            params: { name: row.name },
                                        })
                                    "
                                />
                            </el-tooltip>
                            <el-popconfirm
                                v-if="!row.kind"
                                :title="`Удалить «${row.label}» вместе с таблицей и всеми записями?`"
                                confirm-button-text="Удалить"
                                cancel-button-text="Отмена"
                                width="300"
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
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="dialogOpen" title="Новая коллекция" width="480">
            <el-form label-position="top" @submit.prevent="create">
                <el-form-item label="Название" :error="form.errors.label">
                    <el-input v-model="form.label" placeholder="Товары" />
                </el-form-item>
                <el-form-item label="Системное имя" :error="form.errors.name">
                    <el-input
                        v-model="form.name"
                        placeholder="products"
                        @input="nameTouched = true"
                    />
                    <div class="text-xs text-[var(--el-text-color-secondary)]">
                        Используется в API и имени таблицы. Изменить его позже
                        нельзя.
                    </div>
                </el-form-item>
                <el-form-item label="Описание" :error="form.errors.description">
                    <el-input v-model="form.description" type="textarea" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="create"
                >
                    Создать
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
