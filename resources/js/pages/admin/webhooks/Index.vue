<script setup lang="ts">
import {
    CopyDocument,
    Delete,
    Edit,
    Key,
    List,
    Plus,
    Promotion,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import webhooks from '@/routes/api/webhooks';
import { useCollectionsStore } from '@/stores/collections';
import type { MessageResponse } from '@/types';

type Webhook = {
    id: number;
    name: string;
    url: string;
    events: string[];
    collection: string | null;
    is_active: boolean;
    last_called_at: string | null;
    last_status: number | null;
};

type Delivery = {
    id: number;
    event: string;
    status: number | null;
    success: boolean;
    attempt: number;
    error: string | null;
    created_at: string;
};

const events = [
    { value: 'record.created', label: 'Создана запись' },
    { value: 'record.updated', label: 'Изменена запись' },
    { value: 'record.deleted', label: 'Удалена запись' },
    { value: 'form.submitted', label: 'Заявка с формы' },
    { value: 'page.published', label: 'Опубликована страница' },
];

const collections = useCollectionsStore();
const list = ref<Webhook[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<Webhook | null>(null);
const secret = ref<string | null>(null);
const deliveries = ref<Delivery[] | null>(null);

const form = useForm({
    name: '',
    url: '',
    events: ['record.created'] as string[],
    collection: null as string | null,
    is_active: true,
});

async function load() {
    loading.value = true;

    try {
        list.value = await send<Webhook[]>(webhooks.index());
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await Promise.all([load(), collections.loaded ? null : collections.load()]);
});

function open(webhook: Webhook | null) {
    editing.value = webhook;
    form.clearErrors();
    form.name = webhook?.name ?? '';
    form.url = webhook?.url ?? 'https://';
    form.events = webhook ? [...webhook.events] : ['record.created'];
    form.collection = webhook?.collection ?? null;
    form.is_active = webhook?.is_active ?? true;
    dialogOpen.value = true;
}

async function save() {
    const result = await form.submit<{ message: string; secret?: string }>(
        editing.value ? webhooks.update(editing.value.id) : webhooks.store(),
    );

    if (result) {
        dialogOpen.value = false;
        ElMessage.success(result.message);
        secret.value = result.secret ?? null;
        await load();
    }
}

async function toggle(webhook: Webhook, active: boolean) {
    await send(webhooks.update(webhook.id), { ...webhook, is_active: active });
    await load();
}

async function test(webhook: Webhook) {
    try {
        const result = await send<MessageResponse>(webhooks.test(webhook.id));
        ElMessage.success(result.message);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(error.response?.data.message ?? 'Не доставлено.');
        }
    }

    await load();
}

async function rotate(webhook: Webhook) {
    secret.value = (
        await send<{ secret: string }>(webhooks.secret(webhook.id))
    ).secret;
}

async function showDeliveries(webhook: Webhook) {
    deliveries.value = await send<Delivery[]>(webhooks.deliveries(webhook.id));
}

async function remove(webhook: Webhook) {
    const result = await send<MessageResponse>(webhooks.destroy(webhook.id));
    ElMessage.success(result.message);
    await load();
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
    ElMessage.success('Скопировано.');
}

function eventLabel(value: string) {
    return events.find((e) => e.value === value)?.label ?? value;
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Вебхуки</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Уведомления внешних сервисов (CRM, мессенджеры, интеграции)
                    о событиях в компании.
                </p>
            </div>
            <el-button type="primary" :icon="Plus" @click="open(null)">
                Добавить вебхук
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0" class="mb-4">
            <el-table
                v-loading="loading"
                :data="list"
                row-key="id"
                empty-text="Вебхуков пока нет"
            >
                <el-table-column label="Вебхук" min-width="240">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.name }}</div>
                        <div
                            class="truncate text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ row.url }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="События" min-width="220">
                    <template #default="{ row }">
                        <el-tag
                            v-for="event in row.events"
                            :key="event"
                            size="small"
                            class="mr-1 mb-1"
                        >
                            {{ eventLabel(event) }}
                        </el-tag>
                        <div
                            v-if="row.collection"
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            только «{{
                                collections.find(row.collection)?.label ??
                                row.collection
                            }}»
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="Последний вызов" width="170">
                    <template #default="{ row }">
                        <template v-if="row.last_called_at">
                            <el-tag
                                :type="
                                    row.last_status >= 200 &&
                                    row.last_status < 300
                                        ? 'success'
                                        : 'danger'
                                "
                                size="small"
                            >
                                {{ row.last_status || 'ошибка' }}
                            </el-tag>
                            <div
                                class="text-xs text-[var(--el-text-color-secondary)]"
                            >
                                {{
                                    new Date(row.last_called_at).toLocaleString(
                                        'ru-RU',
                                    )
                                }}
                            </div>
                        </template>
                        <span
                            v-else
                            class="text-xs text-[var(--el-text-color-secondary)]"
                            >—</span
                        >
                    </template>
                </el-table-column>
                <el-table-column label="Вкл." width="70">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="row.is_active"
                            size="small"
                            @update:model-value="
                                (value: unknown) => toggle(row, Boolean(value))
                            "
                        />
                    </template>
                </el-table-column>
                <el-table-column width="210" align="right">
                    <template #default="{ row }">
                        <el-tooltip content="Отправить тест">
                            <el-button
                                :icon="Promotion"
                                circle
                                size="small"
                                @click="test(row)"
                            />
                        </el-tooltip>
                        <el-tooltip content="Журнал доставок">
                            <el-button
                                :icon="List"
                                circle
                                size="small"
                                @click="showDeliveries(row)"
                            />
                        </el-tooltip>
                        <el-tooltip content="Новый секрет">
                            <el-button
                                :icon="Key"
                                circle
                                size="small"
                                @click="rotate(row)"
                            />
                        </el-tooltip>
                        <el-button
                            :icon="Edit"
                            circle
                            size="small"
                            @click="open(row)"
                        />
                        <el-popconfirm
                            :title="`Удалить «${row.name}»?`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
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

        <el-card shadow="never" header="Как проверить подпись">
            <p class="mb-2 text-sm">
                Каждый запрос — POST с JSON. Заголовок
                <code>X-Pagebuilder-Signature: sha256=…</code> — HMAC-SHA256
                тела запроса секретом вебхука. Неудачные доставки повторяются до
                5 раз (через 10 с, 1 мин, 5 мин, 30 мин). Нужен запущенный
                <code>php artisan queue:work</code>.
            </p>
            <pre
                class="overflow-x-auto rounded bg-[var(--el-fill-color-light)] p-3 text-xs"
            >
// PHP
$expected = 'sha256=' . hash_hmac('sha256', file_get_contents('php://input'), $secret);
$valid = hash_equals($expected, $_SERVER['HTTP_X_PAGEBUILDER_SIGNATURE'] ?? '');</pre>
        </el-card>

        <el-dialog
            v-model="dialogOpen"
            :title="editing ? 'Вебхук' : 'Новый вебхук'"
            width="520"
        >
            <el-form label-position="top" @submit.prevent="save">
                <el-form-item label="Название" :error="form.errors.name">
                    <el-input v-model="form.name" placeholder="CRM" />
                </el-form-item>
                <el-form-item label="Адрес (URL)" :error="form.errors.url">
                    <el-input v-model="form.url" placeholder="https://…" />
                </el-form-item>
                <el-form-item label="События" :error="form.errors.events">
                    <el-checkbox-group
                        v-model="form.events"
                        class="flex flex-col"
                    >
                        <el-checkbox
                            v-for="event in events"
                            :key="event.value"
                            :value="event.value"
                        >
                            {{ event.label }}
                        </el-checkbox>
                    </el-checkbox-group>
                </el-form-item>
                <el-form-item
                    label="Только для коллекции"
                    :error="form.errors.collection"
                >
                    <el-select
                        v-model="form.collection"
                        clearable
                        placeholder="Все коллекции"
                    >
                        <el-option
                            v-for="c in collections.items"
                            :key="c.name"
                            :label="c.label"
                            :value="c.name"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-checkbox v-model="form.is_active">Включён</el-checkbox>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="save"
                >
                    Сохранить
                </el-button>
            </template>
        </el-dialog>

        <el-dialog
            :model-value="secret !== null"
            title="Секрет вебхука"
            width="520"
            @close="secret = null"
        >
            <el-alert
                type="warning"
                :closable="false"
                show-icon
                title="Сохраните секрет: им подписываются запросы. Показать его снова нельзя — только создать новый."
                class="mb-4"
            />
            <el-input :model-value="secret ?? ''" readonly>
                <template #append>
                    <el-button
                        :icon="CopyDocument"
                        @click="copy(secret ?? '')"
                    />
                </template>
            </el-input>
        </el-dialog>

        <el-drawer
            :model-value="deliveries !== null"
            title="Журнал доставок"
            size="520px"
            @close="deliveries = null"
        >
            <el-empty
                v-if="!deliveries?.length"
                description="Доставок пока не было"
                :image-size="80"
            />
            <el-timeline v-else>
                <el-timeline-item
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    :timestamp="
                        new Date(delivery.created_at).toLocaleString('ru-RU')
                    "
                    :type="delivery.success ? 'success' : 'danger'"
                >
                    <div class="text-sm">
                        {{ eventLabel(delivery.event) }} · попытка
                        {{ delivery.attempt }} · {{ delivery.status ?? '—' }}
                    </div>
                    <div
                        v-if="delivery.error"
                        class="text-xs text-[var(--el-color-danger)]"
                    >
                        {{ delivery.error }}
                    </div>
                </el-timeline-item>
            </el-timeline>
        </el-drawer>
    </div>
</template>
