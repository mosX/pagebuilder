<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { create } from '@/routes/api/tenants';
import { useSessionStore } from '@/stores/session';
import type { Session } from '@/types';

const session = useSessionStore();
const router = useRouter();

const form = useForm({
    name: '',
});

async function submit() {
    const result = await form.submit<Session>(create());

    if (result) {
        session.set(result);
        ElMessage.success('Компания создана.');
        await router.push({ name: 'dashboard' });
    }
}

async function logout() {
    await session.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <el-card shadow="never">
        <template #header>
            <span class="text-lg font-medium">У вас пока нет компании</span>
        </template>

        <p class="mb-4 text-sm text-[var(--el-text-color-secondary)]">
            Создайте свою компанию или попросите администратора существующей
            компании добавить вас в неё.
        </p>

        <el-form label-position="top" @submit.prevent="submit">
            <el-form-item label="Название компании" :error="form.errors.name">
                <el-input v-model="form.name" autofocus />
            </el-form-item>
            <el-button
                type="primary"
                native-type="submit"
                class="w-full"
                :loading="form.processing"
            >
                Создать компанию
            </el-button>
        </el-form>

        <div class="mt-4 text-center">
            <el-button link @click="logout">Выйти</el-button>
        </div>
    </el-card>
</template>
