<script setup lang="ts">
import { Search } from '@element-plus/icons-vue';
import { onMounted, reactive, ref } from 'vue';
import { send } from '@/lib/http';
import activity from '@/routes/api/activity';
import type { Paginated } from '@/types';

type Entry = {
    id: number;
    action: string;
    subject: string;
    details: Record<string, unknown> | null;
    actor: string;
    ip: string | null;
    created_at: string | null;
};

const actions: Record<string, string> = {
    'collection.created': 'Создана коллекция',
    'collection.updated': 'Изменена коллекция',
    'collection.deleted': 'Удалена коллекция',
    'collection.site_updated': 'Изменён раздел сайта',
    'field.created': 'Добавлено поле',
    'field.updated': 'Изменено поле',
    'field.deleted': 'Удалено поле',
    'record.created': 'Создана запись',
    'record.updated': 'Изменена запись',
    'record.deleted': 'Удалена запись',
    'form.submitted': 'Заявка с сайта',
    'blocks.published': 'Опубликовано',
    'pattern.created': 'Создана секция',
    'pattern.renamed': 'Переименована секция',
    'pattern.deleted': 'Удалена секция',
    'media.uploaded': 'Загружен файл',
    'media.deleted': 'Удалён файл',
    'member.added': 'Добавлен пользователь',
    'member.role_changed': 'Изменена роль',
    'member.removed': 'Удалён пользователь',
    'company.updated': 'Изменена компания',
    'apikey.created': 'Создан API-ключ',
    'apikey.revoked': 'Отозван API-ключ',
    'schema.imported': 'Импорт схемы',
    'site.updated': 'Изменено оформление сайта',
    'webhook.created': 'Создан вебхук',
    'webhook.updated': 'Изменён вебхук',
    'webhook.deleted': 'Удалён вебхук',
    'automation.created': 'Создана автоматизация',
    'automation.updated': 'Изменена автоматизация',
    'automation.deleted': 'Удалена автоматизация',
    'record.translated': 'Переведена запись',
    'site_member.blocked': 'Заблокирован посетитель',
    'site_member.unblocked': 'Разблокирован посетитель',
    'site_member.deleted': 'Удалён посетитель',
};

const groups = [
    { value: '', label: 'Все действия' },
    { value: 'collection', label: 'Коллекции' },
    { value: 'field', label: 'Поля' },
    { value: 'record', label: 'Записи' },
    { value: 'form', label: 'Заявки с сайта' },
    { value: 'blocks', label: 'Публикации' },
    { value: 'pattern', label: 'Секции' },
    { value: 'media', label: 'Медиатека' },
    { value: 'member', label: 'Пользователи' },
    { value: 'site_member', label: 'Посетители сайта' },
    { value: 'apikey', label: 'API-ключи' },
];

const page = ref<Paginated<Entry> | null>(null);
const loading = ref(true);
const filters = reactive({ action: '', search: '' });

async function load(pageNumber = 1) {
    loading.value = true;

    try {
        page.value = await send<Paginated<Entry>>(activity.index(), {
            action: filters.action || undefined,
            search: filters.search || undefined,
            page: pageNumber,
        });
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

function details(entry: Entry) {
    return entry.details
        ? Object.entries(entry.details)
              .map(
                  ([key, value]) =>
                      `${key}: ${Array.isArray(value) ? value.join(', ') : String(value)}`,
              )
              .join(' · ')
        : '';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Журнал действий</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Кто и что менял в компании. Хранится 180 дней.
                </p>
            </div>
            <div class="flex gap-2">
                <el-select
                    v-model="filters.action"
                    class="!w-48"
                    @change="load()"
                >
                    <el-option
                        v-for="group in groups"
                        :key="group.value"
                        :label="group.label"
                        :value="group.value"
                    />
                </el-select>
                <el-input
                    v-model="filters.search"
                    placeholder="Поиск по объекту"
                    :prefix-icon="Search"
                    clearable
                    class="!w-56"
                    @change="load()"
                />
            </div>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="page?.data ?? []"
                row-key="id"
                empty-text="Записей нет"
            >
                <el-table-column label="Когда" width="170">
                    <template #default="{ row }">
                        {{
                            row.created_at
                                ? new Date(row.created_at).toLocaleString(
                                      'ru-RU',
                                  )
                                : ''
                        }}
                    </template>
                </el-table-column>
                <el-table-column prop="actor" label="Кто" min-width="160" />
                <el-table-column label="Действие" min-width="180">
                    <template #default="{ row }">
                        {{ actions[row.action] ?? row.action }}
                    </template>
                </el-table-column>
                <el-table-column label="Объект" min-width="220">
                    <template #default="{ row }">
                        <div>{{ row.subject }}</div>
                        <div
                            v-if="row.details"
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ details(row) }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column prop="ip" label="IP" width="140" />
            </el-table>
        </el-card>

        <div v-if="page && page.last_page > 1" class="mt-4 flex justify-end">
            <el-pagination
                layout="prev, pager, next"
                :total="page.total"
                :page-size="page.per_page"
                :current-page="page.current_page"
                @current-change="load"
            />
        </div>
    </div>
</template>
