<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { computed } from 'vue';
import { useForm } from '@/composables/useForm';
import profile from '@/routes/api/profile';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse, User } from '@/types';

const session = useSessionStore();

const profileForm = useForm({
    name: session.user?.name ?? '',
    email: session.user?.email ?? '',
    current_password: '',
});

// A new e-mail (the login) is confirmed with the current password.
const emailChanged = computed(
    () => profileForm.email !== (session.user?.email ?? ''),
);

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

async function saveProfile() {
    const result = await profileForm.submit<MessageResponse & { user: User }>(
        profile.update(),
    );

    if (result) {
        profileForm.current_password = '';
        profileForm.defaults();
        session.user = result.user;
        ElMessage.success(result.message);
    }
}

async function savePassword() {
    const result = await passwordForm.submit<MessageResponse>(
        profile.password(),
    );

    if (result) {
        passwordForm.reset();
        ElMessage.success(result.message);
    } else {
        passwordForm.reset('password', 'password_confirmation');
    }
}
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold">Профиль</h1>

        <div class="flex max-w-2xl flex-col gap-4">
            <el-card shadow="never" header="Личные данные">
                <el-form label-position="top" @submit.prevent="saveProfile">
                    <el-form-item label="Имя" :error="profileForm.errors.name">
                        <el-input v-model="profileForm.name" />
                    </el-form-item>
                    <el-form-item
                        label="Email"
                        :error="profileForm.errors.email"
                    >
                        <el-input v-model="profileForm.email" type="email" />
                    </el-form-item>
                    <el-form-item
                        v-if="emailChanged"
                        label="Текущий пароль"
                        :error="profileForm.errors.current_password"
                    >
                        <el-input
                            v-model="profileForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            show-password
                        />
                    </el-form-item>
                    <el-button
                        type="primary"
                        native-type="submit"
                        :loading="profileForm.processing"
                        :disabled="!profileForm.isDirty"
                    >
                        Сохранить
                    </el-button>
                </el-form>
            </el-card>

            <el-card shadow="never" header="Смена пароля">
                <el-form label-position="top" @submit.prevent="savePassword">
                    <el-form-item
                        label="Текущий пароль"
                        :error="passwordForm.errors.current_password"
                    >
                        <el-input
                            v-model="passwordForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            show-password
                        />
                    </el-form-item>
                    <el-form-item
                        label="Новый пароль"
                        :error="passwordForm.errors.password"
                    >
                        <el-input
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            show-password
                        />
                    </el-form-item>
                    <el-form-item label="Повторите новый пароль">
                        <el-input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            show-password
                        />
                    </el-form-item>
                    <el-button
                        type="primary"
                        native-type="submit"
                        :loading="passwordForm.processing"
                    >
                        Изменить пароль
                    </el-button>
                </el-form>
            </el-card>
        </div>
    </div>
</template>
