<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { register } from '@/routes/api';
import { useSessionStore } from '@/stores/session';
import type { Session } from '@/types';

const session = useSessionStore();
const router = useRouter();

const form = useForm({
    company: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

async function submit() {
    await session.prepareCsrf();
    const result = await form.submit<Session>(register());
    form.reset('password', 'password_confirmation');

    if (result) {
        session.set(result);
        await router.push({ name: 'dashboard' });
    }
}
</script>

<template>
    <el-card shadow="never">
        <template #header>
            <span class="text-lg font-medium">Регистрация компании</span>
        </template>

        <el-form label-position="top" @submit.prevent="submit">
            <el-form-item
                label="Название компании"
                :error="form.errors.company"
            >
                <el-input v-model="form.company" autofocus />
            </el-form-item>
            <el-form-item label="Ваше имя" :error="form.errors.name">
                <el-input v-model="form.name" autocomplete="name" />
            </el-form-item>
            <el-form-item label="Email" :error="form.errors.email">
                <el-input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                />
            </el-form-item>
            <el-form-item label="Пароль" :error="form.errors.password">
                <el-input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    show-password
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
                Создать аккаунт
            </el-button>
        </el-form>

        <div class="mt-4 text-center text-sm">
            Уже есть аккаунт?
            <router-link
                :to="{ name: 'login' }"
                class="text-[var(--el-color-primary)]"
            >
                Войти
            </router-link>
        </div>
    </el-card>
</template>
