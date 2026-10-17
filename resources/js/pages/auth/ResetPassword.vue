<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { useRoute, useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import passwordRoutes from '@/routes/api/password';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse } from '@/types';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();

const form = useForm({
    token: String(route.params.token),
    email: typeof route.query.email === 'string' ? route.query.email : '',
    password: '',
    password_confirmation: '',
});

async function submit() {
    await session.prepareCsrf();
    const result = await form.submit<MessageResponse>(passwordRoutes.reset());
    form.reset('password', 'password_confirmation');

    if (result) {
        ElMessage.success(result.message);
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <el-card shadow="never">
        <template #header>
            <span class="text-lg font-medium">Новый пароль</span>
        </template>

        <el-form label-position="top" @submit.prevent="submit">
            <el-form-item label="Email" :error="form.errors.email">
                <el-input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                />
            </el-form-item>
            <el-form-item label="Новый пароль" :error="form.errors.password">
                <el-input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    show-password
                    autofocus
                />
            </el-form-item>
            <el-form-item label="Повторите пароль">
                <el-input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    show-password
                />
            </el-form-item>
            <el-button
                type="primary"
                native-type="submit"
                class="w-full"
                :loading="form.processing"
            >
                Сохранить пароль
            </el-button>
        </el-form>
    </el-card>
</template>
