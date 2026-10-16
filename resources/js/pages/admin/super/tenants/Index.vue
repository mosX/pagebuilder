<script setup lang="ts">
import { Delete, Edit, Plus, Right, Search } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import tenants from '@/routes/api/super/tenants';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse, Paginated } from '@/types';

type Tenant = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    members_count: number;
    site_url: string;
    created_at: string;
};

const session = useSessionStore();
const router = useRouter();

const page = ref<Paginated<Tenant> | null>(null);
const loading = ref(true);
const search = ref('');

async function load(pageNumber = 1) {
    loading.value = true;

    try {
        page.value = await send<Paginated<Tenant>>(tenants.index(), {
            search: search.value || undefined,
            page: pageNumber,
        });
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

const createOpen = ref(false);
const createForm = useForm({
    name: '',
    slug: '',
    owner_email: '',
});

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

async function create() {
    const result = await createForm.submit<MessageResponse>(tenants.store());

    if (result) {
        createOpen.value = false;
        ElMessage.success(result.message);
        await Promise.all([load(page.value?.current_page), session.load(true)]);
    }
}

const editing = ref<Tenant | null>(null);
const editForm = useForm({
    name: '',
    slug: '',
    is_active: true,
});

function openEdit(tenant: Tenant) {
    editing.value = tenant;
    editForm.clearErrors();
    editForm.name = tenant.name;
    editForm.slug = tenant.slug;
    editForm.is_active = tenant.is_active;
}

async function update() {
    if (!editing.value) {
        return;
    }

    const result = await editForm.submit<MessageResponse>(
        tenants.update(editing.value.id),
    );

    if (result) {
        editing.value = null;
        ElMessage.success(result.message);
        await Promise.all([load(page.value?.current_page), session.load(true)]);
    }
}

async function remove(tenant: Tenant) {
    const result = await send<MessageResponse>(tenants.destroy(tenant.id));
    ElMessage.success(result.message);
    await Promise.all([load(page.value?.current_page), session.load(true)]);
}

async function enter(tenant: Tenant) {
    await session.switchTenant(tenant.id);
    ElMessage.success(`Вы работаете в компании «${tenant.name}».`);
    await router.push({ name: 'dashboard' });
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Все компании</h1>
            <div class="flex gap-2">
                <el-input
                    v-model="search"
                    placeholder="Поиск по названию или slug"
                    :prefix-icon="Search"
                    clearable
                    class="!w-72"
                    @change="load()"
                />
                <el-button type="primary" :icon="Plus" @click="openCreate">
                    Создать
                </el-button>
            </div>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table v-loading="loading" :data="page?.data ?? []" row-key="id">
                <el-table-column prop="id" label="ID" width="70" />
                <el-table-column prop="name" label="Название" min-width="200" />
                <el-table-column label="Сайт" min-width="200">
                    <template #default="{ row }">
                        <a
                            :href="row.site_url"
                            target="_blank"
                            rel="noopener"
                            class="text-[var(--el-color-primary)]"
                        >
                            {{ row.slug }}
                        </a>
                    </template>
                </el-table-column>
                <el-table-column
                    prop="members_count"
                    label="Пользователей"
                    width="140"
                />
                <el-table-column label="Статус" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.is_active ? 'success' : 'info'">
                            {{ row.is_active ? 'Активна' : 'Отключена' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column width="160" align="right">
                    <template #default="{ row }">
                        <el-tooltip content="Перейти в компанию">
                            <el-button
                                :icon="Right"
                                circle
                                size="small"
                                @click="enter(row)"
                            />
                        </el-tooltip>
                        <el-button
                            :icon="Edit"
                            circle
                            size="small"
                            @click="openEdit(row)"
                        />
                        <el-popconfirm
                            :title="`Удалить «${row.name}» со всеми данными?`"
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

        <div v-if="page && page.last_page > 1" class="mt-4 flex justify-end">
            <el-pagination
                layout="prev, pager, next"
                :total="page.total"
                :page-size="page.per_page"
                :current-page="page.current_page"
                @current-change="load"
            />
        </div>

        <el-dialog v-model="createOpen" title="Новая компания" width="480">
            <el-form label-position="top" @submit.prevent="create">
                <el-form-item label="Название" :error="createForm.errors.name">
                    <el-input v-model="createForm.name" />
                </el-form-item>
                <el-form-item
                    label="Slug (необязательно)"
                    :error="createForm.errors.slug"
                >
                    <el-input
                        v-model="createForm.slug"
                        placeholder="Сгенерируется из названия"
                    />
                </el-form-item>
                <el-form-item
                    label="Email владельца (необязательно)"
                    :error="createForm.errors.owner_email"
                >
                    <el-input
                        v-model="createForm.owner_email"
                        placeholder="Существующий пользователь"
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="createOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="createForm.processing"
                    @click="create"
                >
                    Создать
                </el-button>
            </template>
        </el-dialog>

        <el-dialog
            :model-value="editing !== null"
            title="Редактирование компании"
            width="480"
            @close="editing = null"
        >
            <el-form label-position="top" @submit.prevent="update">
                <el-form-item label="Название" :error="editForm.errors.name">
                    <el-input v-model="editForm.name" />
                </el-form-item>
                <el-form-item label="Slug" :error="editForm.errors.slug">
                    <el-input v-model="editForm.slug" />
                </el-form-item>
                <el-form-item label="Активна">
                    <el-switch v-model="editForm.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editing = null">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="editForm.processing"
                    @click="update"
                >
                    Сохранить
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
