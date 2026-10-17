<script setup lang="ts">
import { CopyDocument, Delete, Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import apiKeys from '@/routes/api/api-keys';
import type { MessageResponse } from '@/types';

type ApiKey = {
    id: number;
    name: string;
    prefix: string;
    can_write: boolean;
    last_used_at: string | null;
    created_at: string | null;
};

const keys = ref<ApiKey[]>([]);
const loading = ref(true);
const createOpen = ref(false);
/** The plain key, shown once right after creation. */
const newToken = ref<string | null>(null);

const form = useForm({ name: '', can_write: false });
const baseUrl = computed(() => `${location.origin}/api/v1`);

async function load() {
    loading.value = true;

    try {
        keys.value = await send<ApiKey[]>(apiKeys.index());
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openCreate() {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}

async function create() {
    const result = await form.submit<{ token: string }>(apiKeys.store());

    if (result) {
        createOpen.value = false;
        newToken.value = result.token;
        await load();
    }
}

async function revoke(key: ApiKey) {
    const result = await send<MessageResponse>(apiKeys.destroy(key.id));
    ElMessage.success(result.message);
    await load();
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
    ElMessage.success('Скопировано.');
}

function date(value: string | null) {
    return value ? new Date(value).toLocaleString('ru-RU') : 'никогда';
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">API-ключи</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Доступ к коллекциям и страницам компании для внешних
                    приложений: мобильного приложения, другого сайта,
                    интеграций.
                </p>
            </div>
            <el-button type="primary" :icon="Plus" @click="openCreate">
                Создать ключ
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0" class="mb-4">
            <el-table
                v-loading="loading"
                :data="keys"
                row-key="id"
                empty-text="Ключей пока нет"
            >
                <el-table-column prop="name" label="Название" min-width="180" />
                <el-table-column label="Ключ" width="170">
                    <template #default="{ row }">
                        <code class="text-xs">{{ row.prefix }}…</code>
                    </template>
                </el-table-column>
                <el-table-column label="Доступ" width="160">
                    <template #default="{ row }">
                        <el-tag
                            :type="row.can_write ? 'warning' : 'info'"
                            size="small"
                        >
                            {{
                                row.can_write
                                    ? 'чтение и запись'
                                    : 'только чтение'
                            }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="Использован" width="170">
                    <template #default="{ row }">{{
                        date(row.last_used_at)
                    }}</template>
                </el-table-column>
                <el-table-column width="70" align="right">
                    <template #default="{ row }">
                        <el-popconfirm
                            :title="`Отозвать «${row.name}»? Приложения с этим ключом потеряют доступ.`"
                            confirm-button-text="Отозвать"
                            cancel-button-text="Отмена"
                            width="300"
                            @confirm="revoke(row)"
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

        <el-card shadow="never" header="Как пользоваться">
            <div class="flex flex-col gap-3 text-sm">
                <p>
                    Передавайте ключ в заголовке
                    <code>Authorization: Bearer pb_…</code>. Базовый адрес:
                    <code>{{ baseUrl }}</code>
                </p>
                <pre
                    class="overflow-x-auto rounded bg-[var(--el-fill-color-light)] p-3 text-xs"
                >
GET  /collections                                   структура коллекций
GET  /collections/{name}/records?search=&amp;sort=&amp;direction=&amp;per_page=&amp;filter[поле]=значение
GET  /collections/{name}/records/{id}
POST /collections/{name}/records                     (ключ с правом записи)
PUT  /collections/{name}/records/{id}                (ключ с правом записи)
DELETE /collections/{name}/records/{id}              (ключ с правом записи)
GET  /pages/{slug}                                   опубликованная страница с блоками

curl -H "Authorization: Bearer pb_…" {{ baseUrl }}/collections</pre>
                <p class="text-[var(--el-text-color-secondary)]">
                    Лимит — 120 запросов в минуту на ключ.
                </p>
            </div>
        </el-card>

        <el-dialog v-model="createOpen" title="Новый API-ключ" width="460">
            <el-form label-position="top" @submit.prevent="create">
                <el-form-item label="Название" :error="form.errors.name">
                    <el-input
                        v-model="form.name"
                        placeholder="Мобильное приложение"
                    />
                </el-form-item>
                <el-form-item>
                    <el-checkbox v-model="form.can_write">
                        Разрешить создавать, изменять и удалять записи
                    </el-checkbox>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="createOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="create"
                >
                    Создать
                </el-button>
            </template>
        </el-dialog>

        <el-dialog
            :model-value="newToken !== null"
            title="Ключ создан"
            width="560"
            :close-on-click-modal="false"
            @close="newToken = null"
        >
            <el-alert
                type="warning"
                :closable="false"
                show-icon
                title="Скопируйте ключ сейчас. Мы храним только его отпечаток и показать его снова не сможем."
                class="mb-4"
            />
            <el-input :model-value="newToken ?? ''" readonly>
                <template #append>
                    <el-button
                        :icon="CopyDocument"
                        @click="copy(newToken ?? '')"
                    />
                </template>
            </el-input>
            <template #footer>
                <el-button type="primary" @click="newToken = null"
                    >Готово</el-button
                >
            </template>
        </el-dialog>
    </div>
</template>
