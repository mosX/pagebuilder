<script setup lang="ts">
import { Delete, Edit, List, Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import automations from '@/routes/api/automations';
import { useCollectionsStore } from '@/stores/collections';
import type { MessageResponse } from '@/types';

type Condition = { field: string; operator: string; value: string };
type Value = { field: string; value: string };
type Action = {
    type: 'send_email' | 'update_record' | 'create_record' | 'webhook';
    to?: string;
    subject?: string;
    body?: string;
    collection?: string;
    values?: Value[];
    url?: string;
};
type Automation = {
    id: number;
    name: string;
    is_active: boolean;
    trigger: { event: string; collection: string; conditions: Condition[] };
    actions: Action[];
    runs_count: number;
    last_run_at: string | null;
    last_error: string | null;
};
type Run = {
    id: number;
    success: boolean;
    subject: string | null;
    message: string | null;
    created_at: string;
};

const events = [
    { value: 'record.created', label: 'создана запись' },
    { value: 'record.updated', label: 'изменена запись' },
    { value: 'record.deleted', label: 'удалена запись' },
    { value: 'form.submitted', label: 'пришла заявка с формы' },
];
const operators = [
    { value: 'equals', label: 'равно' },
    { value: 'not_equals', label: 'не равно' },
    { value: 'contains', label: 'содержит' },
    { value: 'empty', label: 'пустое' },
    { value: 'not_empty', label: 'заполнено' },
    { value: 'changed', label: 'изменилось' },
];
const actionTypes = [
    { value: 'send_email', label: 'Отправить письмо' },
    { value: 'update_record', label: 'Изменить эту запись' },
    { value: 'create_record', label: 'Создать запись' },
    { value: 'webhook', label: 'Вызвать вебхук' },
];

const collections = useCollectionsStore();
const list = ref<Automation[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<Automation | null>(null);
const runs = ref<Run[] | null>(null);

const form = useForm({
    name: '',
    is_active: true,
    trigger: {
        event: 'record.created',
        collection: '',
        conditions: [] as Condition[],
    },
    actions: [] as Action[],
});

const fieldsOf = (name: string | undefined) =>
    (collections.find(name ?? '')?.fields ?? []).filter(
        (f) => f.type !== 'blocks',
    );
const triggerFields = computed(() => fieldsOf(form.trigger.collection));

async function load() {
    loading.value = true;

    try {
        list.value = await send<Automation[]>(automations.index());
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await Promise.all([load(), collections.loaded ? null : collections.load()]);
});

function open(automation: Automation | null) {
    editing.value = automation;
    form.clearErrors();
    form.name = automation?.name ?? '';
    form.is_active = automation?.is_active ?? true;
    form.trigger = automation
        ? structuredClone(automation.trigger)
        : {
              event: 'record.created',
              collection: collections.items[0]?.name ?? '',
              conditions: [],
          };
    form.actions = automation
        ? structuredClone(automation.actions)
        : [{ type: 'send_email', to: '', subject: '', body: '' }];
    dialogOpen.value = true;
}

function addAction() {
    form.actions = [
        ...form.actions,
        { type: 'send_email', to: '', subject: '', body: '' },
    ];
}

function changeType(action: Action, type: Action['type']) {
    action.type = type;
    action.values =
        type === 'update_record' || type === 'create_record'
            ? (action.values ?? [{ field: '', value: '' }])
            : undefined;
}

async function save() {
    const result = await form.submit<{ message: string }>(
        editing.value
            ? automations.update(editing.value.id)
            : automations.store(),
    );

    if (result) {
        dialogOpen.value = false;
        ElMessage.success(result.message);
        await load();
    }
}

async function toggle(automation: Automation, active: boolean) {
    await send(automations.update(automation.id), {
        ...automation,
        is_active: active,
    });
    await load();
}

async function remove(automation: Automation) {
    const result = await send<MessageResponse>(
        automations.destroy(automation.id),
    );
    ElMessage.success(result.message);
    await load();
}

async function showRuns(automation: Automation) {
    runs.value = await send<Run[]>(automations.runs(automation.id));
}

function summary(automation: Automation) {
    const event =
        events.find((e) => e.value === automation.trigger.event)?.label ?? '';
    const collection =
        collections.find(automation.trigger.collection)?.label ??
        automation.trigger.collection;
    const actions = automation.actions
        .map((a) =>
            actionTypes.find((t) => t.value === a.type)?.label.toLowerCase(),
        )
        .join(', ');

    return `Когда в «${collection}» ${event} → ${actions}`;
}

/** A template placeholder as users type it: {{ field }}. */
function placeholder(field: string) {
    return `{{ ${field} }}`;
}

/** First validation error of an action (keys like actions.0.to). */
function actionError(index: number) {
    return Object.entries(form.errors).find(([key]) =>
        key.startsWith(`actions.${index}`),
    )?.[1];
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Автоматизации</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    «Когда… → сделай…»: письма, изменение и создание записей,
                    вызов вебхуков. Выполняются в фоне (нужен
                    <code>php artisan queue:work</code>).
                </p>
            </div>
            <el-button type="primary" :icon="Plus" @click="open(null)">
                Новая автоматизация
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="list"
                row-key="id"
                empty-text="Автоматизаций пока нет"
            >
                <el-table-column label="Автоматизация" min-width="320">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.name }}</div>
                        <div
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ summary(row) }}
                        </div>
                        <div
                            v-if="row.last_error"
                            class="text-xs text-[var(--el-color-danger)]"
                        >
                            Ошибка: {{ row.last_error }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column
                    label="Запусков"
                    width="100"
                    prop="runs_count"
                />
                <el-table-column label="Последний" width="170">
                    <template #default="{ row }">
                        {{
                            row.last_run_at
                                ? new Date(row.last_run_at).toLocaleString(
                                      'ru-RU',
                                  )
                                : '—'
                        }}
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
                <el-table-column width="140" align="right">
                    <template #default="{ row }">
                        <el-tooltip content="Журнал запусков">
                            <el-button
                                :icon="List"
                                circle
                                size="small"
                                @click="showRuns(row)"
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

        <el-drawer
            v-model="dialogOpen"
            :title="editing ? 'Автоматизация' : 'Новая автоматизация'"
            size="640px"
        >
            <el-form label-position="top" @submit.prevent="save">
                <el-form-item label="Название" :error="form.errors.name">
                    <el-input
                        v-model="form.name"
                        placeholder="Ответ на заявку"
                    />
                </el-form-item>

                <el-divider content-position="left">Когда</el-divider>
                <div class="grid grid-cols-2 gap-3">
                    <el-form-item
                        label="Коллекция"
                        :error="form.errors['trigger.collection']"
                    >
                        <el-select v-model="form.trigger.collection">
                            <el-option
                                v-for="c in collections.items"
                                :key="c.name"
                                :label="c.label"
                                :value="c.name"
                            />
                        </el-select>
                    </el-form-item>
                    <el-form-item
                        label="Событие"
                        :error="form.errors['trigger.event']"
                    >
                        <el-select v-model="form.trigger.event">
                            <el-option
                                v-for="e in events"
                                :key="e.value"
                                :label="e.label"
                                :value="e.value"
                            />
                        </el-select>
                    </el-form-item>
                </div>

                <div class="mb-2 text-sm">
                    Условия (все должны выполняться):
                </div>
                <div
                    v-for="(condition, i) in form.trigger.conditions"
                    :key="i"
                    class="mb-2 flex items-start gap-2"
                >
                    <el-select
                        v-model="condition.field"
                        placeholder="Поле"
                        class="!w-44"
                    >
                        <el-option
                            v-for="f in triggerFields"
                            :key="f.name"
                            :label="f.label"
                            :value="f.name"
                        />
                    </el-select>
                    <el-select v-model="condition.operator" class="!w-36">
                        <el-option
                            v-for="o in operators"
                            :key="o.value"
                            :label="o.label"
                            :value="o.value"
                        />
                    </el-select>
                    <el-input
                        v-if="
                            !['empty', 'not_empty', 'changed'].includes(
                                condition.operator,
                            )
                        "
                        v-model="condition.value"
                        placeholder="Значение"
                        class="flex-1"
                    />
                    <el-button
                        :icon="Delete"
                        link
                        type="danger"
                        @click="form.trigger.conditions.splice(i, 1)"
                    />
                </div>
                <el-button
                    size="small"
                    :icon="Plus"
                    class="mb-2"
                    @click="
                        form.trigger.conditions.push({
                            field: '',
                            operator: 'equals',
                            value: '',
                        })
                    "
                >
                    Условие
                </el-button>

                <el-divider content-position="left">Сделать</el-divider>
                <p class="mb-3 text-xs text-[var(--el-text-color-secondary)]">
                    В текстах можно подставлять значения записи:
                    <code
                        v-for="f in triggerFields"
                        :key="f.name"
                        class="mr-1"
                        >{{ placeholder(f.name) }}</code
                    >
                    <code>{{ placeholder('id') }}</code>
                </p>

                <el-card
                    v-for="(action, i) in form.actions"
                    :key="i"
                    shadow="never"
                    class="mb-3"
                    body-class="flex flex-col gap-2"
                >
                    <div class="flex items-center gap-2">
                        <el-select
                            :model-value="action.type"
                            class="!w-56"
                            @update:model-value="
                                (type: Action['type']) =>
                                    changeType(action, type)
                            "
                        >
                            <el-option
                                v-for="t in actionTypes"
                                :key="t.value"
                                :label="t.label"
                                :value="t.value"
                            />
                        </el-select>
                        <span class="flex-1" />
                        <el-button
                            :icon="Delete"
                            link
                            type="danger"
                            @click="form.actions.splice(i, 1)"
                        />
                    </div>

                    <template v-if="action.type === 'send_email'">
                        <el-input
                            v-model="action.to"
                            placeholder="Кому: адрес или {{ email }}"
                        />
                        <el-input v-model="action.subject" placeholder="Тема" />
                        <el-input
                            v-model="action.body"
                            type="textarea"
                            :rows="4"
                            placeholder="Текст письма"
                        />
                    </template>

                    <template v-else-if="action.type === 'webhook'">
                        <el-input
                            v-model="action.url"
                            placeholder="https://…"
                        />
                    </template>

                    <template v-else>
                        <el-select
                            v-if="action.type === 'create_record'"
                            v-model="action.collection"
                            placeholder="В коллекции"
                        >
                            <el-option
                                v-for="c in collections.items"
                                :key="c.name"
                                :label="c.label"
                                :value="c.name"
                            />
                        </el-select>
                        <div
                            v-for="(item, j) in action.values ?? []"
                            :key="j"
                            class="flex gap-2"
                        >
                            <el-select
                                v-model="item.field"
                                placeholder="Поле"
                                class="!w-48"
                            >
                                <el-option
                                    v-for="f in fieldsOf(
                                        action.type === 'create_record'
                                            ? action.collection
                                            : form.trigger.collection,
                                    )"
                                    :key="f.name"
                                    :label="f.label"
                                    :value="f.name"
                                />
                            </el-select>
                            <el-input
                                v-model="item.value"
                                placeholder="Значение или {{ поле }}"
                                class="flex-1"
                            />
                            <el-button
                                :icon="Delete"
                                link
                                type="danger"
                                @click="action.values?.splice(j, 1)"
                            />
                        </div>
                        <el-button
                            size="small"
                            :icon="Plus"
                            class="self-start"
                            @click="
                                (action.values ??= []).push({
                                    field: '',
                                    value: '',
                                })
                            "
                        >
                            Поле
                        </el-button>
                    </template>

                    <div
                        v-if="actionError(i)"
                        class="text-xs text-[var(--el-color-danger)]"
                    >
                        {{ actionError(i) }}
                    </div>
                </el-card>
                <el-button size="small" :icon="Plus" @click="addAction"
                    >Действие</el-button
                >

                <el-divider />
                <el-checkbox v-model="form.is_active">Включена</el-checkbox>
            </el-form>
            <template #footer>
                <el-button @click="dialogOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="save"
                    >Сохранить</el-button
                >
            </template>
        </el-drawer>

        <el-drawer
            :model-value="runs !== null"
            title="Журнал запусков"
            size="480px"
            @close="runs = null"
        >
            <el-empty
                v-if="!runs?.length"
                description="Запусков пока не было"
                :image-size="80"
            />
            <el-timeline v-else>
                <el-timeline-item
                    v-for="run in runs"
                    :key="run.id"
                    :timestamp="
                        new Date(run.created_at).toLocaleString('ru-RU')
                    "
                    :type="run.success ? 'success' : 'danger'"
                >
                    <div class="text-sm font-medium">{{ run.subject }}</div>
                    <div
                        class="text-xs"
                        :class="
                            run.success ? '' : 'text-[var(--el-color-danger)]'
                        "
                    >
                        {{ run.message }}
                    </div>
                </el-timeline-item>
            </el-timeline>
        </el-drawer>
    </div>
</template>
