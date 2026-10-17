<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { login } from '@/routes/api';
import { useSessionStore } from '@/stores/session';
import type { Session } from '@/types';

const session = useSessionStore();
const route = useRoute();
const router = useRouter();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

async function submit() {
    await session.prepareCsrf();
    const result = await form.submit<Session>(login());
    form.reset('password');

    if (result) {
        session.set(result);
        const redirect =
            typeof route.query.redirect === 'string'
                ? route.query.redirect
                : '/admin';
        await router.push(redirect);
    }
}
</script>

<template>
    <el-card shadow="never">
        <template #header>
            <span class="text-lg font-medium">Вход в админку</span>
        </template>

        <el-form label-position="top" @submit.prevent="submit">
            <el-form-item label="Email" :error="form.errors.email">
                <el-input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    autofocus
                />
            </el-form-item>
            <el-form-item label="Пароль" :error="form.errors.password">
                <el-input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    show-password
                />
            </el-form-item>
            <el-form-item>
                <div class="flex w-full items-center justify-between">
                    <el-checkbox v-model="form.remember"
                        >Запомнить меня</el-checkbox
                    >
                    <router-link
                        :to="{ name: 'password.forgot' }"
                        class="text-sm text-[var(--el-color-primary)]"
                    >
                        Забыли пароль?
                    </router-link>
                </div>
            </el-form-item>
            <el-button
                type="primary"
                native-type="submit"
                class="w-full"
                :loading="form.processing"
            >
                Войти
            </el-button>
        </el-form>

        <div class="mt-4 text-center text-sm">
            Нет аккаунта?
            <router-link
                :to="{ name: 'register' }"
                class="text-[var(--el-color-primary)]"
            >
                Зарегистрироваться
            </router-link>
        </div>
    </el-card>
</template>
