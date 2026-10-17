<script setup lang="ts">
import { Search } from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { computed, onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import siteMembers from '@/routes/api/site-members';
import { useSessionStore } from '@/stores/session';
import type { Paginated } from '@/types';

type Visitor = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    profile: Record<string, string> | null;
    is_blocked: boolean;
    last_login_at: string | null;
    created_at: string | null;
};

const session = useSessionStore();
const canManage = computed(() => session.can('content.manage'));

const page = ref<Paginated<Visitor> | null>(null);
const loading = ref(true);
const search = ref('');

async function load(pageNumber = 1) {
    loading.value = true;

    try {
        page.value = await send<Paginated<Visitor>>(siteMembers.index(), {
            search: search.value || undefined,
            page: pageNumber,
        });
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

function date(value: string | null) {
    return value ? new Date(value).toLocaleString('ru-RU') : '—';
}

async function toggleBlock(visitor: Visitor) {
    const result = await send<{ message: string; member: Visitor }>(
        siteMembers.update(visitor.id),
        { is_blocked: !visitor.is_blocked },
    );

    Object.assign(visitor, result.member);
    ElMessage.success(result.message);
}

async function remove(visitor: Visitor) {
    try {
        await ElMessageBox.confirm(
            `Удалить аккаунт ${visitor.email}? Заявки посетителя останутся в коллекциях.`,
            'Удаление',
            {
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена',
                type: 'warning',
            },
        );
    } catch {
        return;
    }

    const result = await send<{ message: string }>(
        siteMembers.destroy(visitor.id),
    );
    ElMessage.success(result.message);
    await load(page.value?.current_page ?? 1);
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Посетители</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Аккаунты посетителей сайта. Кабинет включается в разделе
                    «Сайт» → «Кабинет».
                </p>
            </div>
            <el-input
                v-model="search"
                placeholder="Имя или e-mail"
                :prefix-icon="Search"
                clearable
                class="!w-64"
                @change="load()"
            />
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="page?.data ?? []"
                row-key="id"
                empty-text="Посетителей пока нет"
            >
                <el-table-column label="Посетитель" min-width="220">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.name }}</div>
                        <div
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ row.email }}
                        </div>
                        <div
                            v-if="row.profile"
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ Object.values(row.profile).join(' · ') }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="Статус" width="200">
                    <template #default="{ row }">
                        <div class="flex flex-wrap gap-1">
                            <el-tag v-if="row.is_blocked" type="danger">
                                Заблокирован
                            </el-tag>
                            <el-tag v-if="row.email_verified_at" type="success">
                                E-mail подтверждён
                            </el-tag>
                            <el-tag v-else type="info"> Не подтверждён </el-tag>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="Регистрация" width="170">
                    <template #default="{ row }">
                        {{ date(row.created_at) }}
                    </template>
                </el-table-column>
                <el-table-column label="Последний вход" width="170">
                    <template #default="{ row }">
                        {{ date(row.last_login_at) }}
                    </template>
                </el-table-column>
                <el-table-column v-if="canManage" width="220" align="right">
                    <template #default="{ row }">
                        <el-button size="small" @click="toggleBlock(row)">
                            {{
                                row.is_blocked
                                    ? 'Разблокировать'
                                    : 'Заблокировать'
                            }}
                        </el-button>
                        <el-button
                            size="small"
                            type="danger"
                            plain
                            @click="remove(row)"
                        >
                            Удалить
                        </el-button>
                    </template>
                </el-table-column>
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
