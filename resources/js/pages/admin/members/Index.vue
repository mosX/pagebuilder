<script setup lang="ts">
import { Delete, Message, Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import memberInvitations from '@/routes/api/member-invitations';
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

type Invitation = {
    id: number;
    email: string;
    role: Option;
    invited_by: string | null;
    expires_at: string;
    expired: boolean;
    can_manage: boolean;
};

type MembersResponse = {
    members: Member[];
    invitations: Invitation[];
    can_manage: boolean;
    roles: Option[];
};

const list = ref<Member[]>([]);
const invitations = ref<Invitation[]>([]);
const canManage = ref(false);
const roles = ref<Option[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);

const form = useForm({
    email: '',
    role: 'viewer',
});

async function load() {
    loading.value = true;

    try {
        const result = await send<MembersResponse>(members.index());
        list.value = result.members;
        invitations.value = result.invitations;
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

function resendInvitation(invitation: Invitation) {
    return rowAction(() =>
        send<MessageResponse>(memberInvitations.resend(invitation.id)),
    );
}

function revokeInvitation(invitation: Invitation) {
    return rowAction(() =>
        send<MessageResponse>(memberInvitations.destroy(invitation.id)),
    );
}

function date(value: string) {
    return new Date(value).toLocaleDateString('ru-RU');
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
                Пригласить
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

        <el-card
            v-if="invitations.length"
            shadow="never"
            header="Приглашения"
            body-class="!p-0"
            class="mt-4"
        >
            <el-table :data="invitations" row-key="id">
                <el-table-column prop="email" label="Email" min-width="220" />
                <el-table-column label="Роль" width="160">
                    <template #default="{ row }">
                        <el-tag type="info">{{ row.role.label }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="Статус" min-width="200">
                    <template #default="{ row }">
                        <el-tag v-if="row.expired" type="danger">
                            Истекло
                        </el-tag>
                        <span v-else class="text-sm">
                            Ждёт ответа до {{ date(row.expires_at) }}
                        </span>
                        <div
                            v-if="row.invited_by"
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            Пригласил(а): {{ row.invited_by }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column width="120" align="right">
                    <template #default="{ row }">
                        <template v-if="row.can_manage">
                            <el-tooltip content="Отправить ещё раз">
                                <el-button
                                    :icon="Message"
                                    circle
                                    plain
                                    size="small"
                                    @click="resendInvitation(row)"
                                />
                            </el-tooltip>
                            <el-popconfirm
                                :title="`Отозвать приглашение для ${row.email}?`"
                                confirm-button-text="Отозвать"
                                cancel-button-text="Отмена"
                                width="260"
                                @confirm="revokeInvitation(row)"
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

        <el-dialog
            v-model="dialogOpen"
            title="Пригласить пользователя"
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
                <p class="text-xs text-[var(--el-text-color-secondary)]">
                    На этот адрес придёт письмо со ссылкой. Пользователь сам
                    примет приглашение: войдёт в свой аккаунт или создаст новый.
                    Ссылка действует 7 дней.
                </p>
            </el-form>

            <template #footer>
                <el-button @click="dialogOpen = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="addMember"
                >
                    Отправить приглашение
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
