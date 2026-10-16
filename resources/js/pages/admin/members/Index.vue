<script setup lang="ts">
import { Delete, Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import members from '@/routes/api/members';
import type { MessageResponse, Option } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: Option;
    is_self: boolean;
    can_manage: boolean;
};

type MembersResponse = {
    members: Member[];
    can_manage: boolean;
    roles: Option[];
};

const list = ref<Member[]>([]);
const canManage = ref(false);
const roles = ref<Option[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);

const form = useForm({
    email: '',
    role: 'viewer',
    name: '',
    password: '',
});

async function load() {
    loading.value = true;

    try {
        const result = await send<MembersResponse>(members.index());
        list.value = result.members;
        canManage.value = result.can_manage;
        roles.value = result.roles;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function openDialog() {
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

async function addMember() {
    const result = await form.submit<MessageResponse>(members.store());

    if (result) {
        dialogOpen.value = false;
        ElMessage.success(result.message);
        await load();
    }
}

/**
 * Row actions report business-rule failures (422 on `member`) as a toast.
 */
async function rowAction(action: () => Promise<MessageResponse>) {
    try {
        ElMessage.success((await action()).message);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(error.response?.data.message ?? '');
        }
    }

    await load();
}

function changeRole(member: Member, role: string) {
    return rowAction(() =>
        send<MessageResponse>(members.update(member.id), { role }),
    );
}

function removeMember(member: Member) {
    return rowAction(() => send<MessageResponse>(members.destroy(member.id)));
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Пользователи компании</h1>
            <el-button
                v-if="canManage"
                type="primary"
                :icon="Plus"
                @click="openDialog"
            >
                Добавить
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table v-loading="loading" :data="list" row-key="id">
                <el-table-column label="Имя" min-width="180">
                    <template #default="{ row }">
                        {{ row.name }}
                        <el-tag v-if="row.is_self" size="small" class="ml-2">
                            Вы
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="email" label="Email" min-width="220" />
                <el-table-column label="Роль" width="220">
                    <template #default="{ row }">
                        <el-select
                            v-if="row.can_manage"
                            :model-value="row.role.value"
                            size="small"
                            @change="(value: string) => changeRole(row, value)"
                        >
                            <el-option
                                v-for="role in roles"
                                :key="role.value"
                                :label="role.label"
                                :value="role.value"
                            />
                        </el-select>
                        <el-tag v-else type="info">{{ row.role.label }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column v-if="canManage" width="80" align="right">
                    <template #default="{ row }">
                        <el-popconfirm
                            v-if="row.can_manage"
                            :title="`Удалить ${row.name} из компании?`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="260"
                            @confirm="removeMember(row)"
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
            v-model="dialogOpen"
            title="Добавить пользователя"
            width="480"
        >
            <el-form label-position="top" @submit.prevent="addMember">
                <el-form-item label="Email" :error="form.errors.email">
                    <el-input v-model="form.email" type="email" />
                </el-form-item>
                <el-form-item label="Роль" :error="form.errors.role">
                    <el-select v-model="form.role">
                        <el-option
                            v-for="role in roles"
                            :key="role.value"
                            :label="role.label"
                            :value="role.value"
                        />
                    </el-select>
                </el-form-item>

                <el-divider content-position="left">
                    Только для нового пользователя
                </el-divider>
                <p class="mb-3 text-xs text-[var(--el-text-color-secondary)]">
                    Если пользователь с таким email уже зарегистрирован, он
                    будет просто добавлен в компанию.
                </p>
                <el-form-item label="Имя" :error="form.errors.name">
                    <el-input v-model="form.name" />
                </el-form-item>
                <el-form-item label="Пароль" :error="form.errors.password">
                    <el-input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        show-password
                    />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="dialogOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="addMember"
                >
                    Добавить
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
