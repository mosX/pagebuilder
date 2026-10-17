<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@/composables/useForm';
import passwordRoutes from '@/routes/api/password';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse } from '@/types';

const session = useSessionStore();
const sent = ref('');
const form = useForm({ email: '' });

async function submit() {
    await session.prepareCsrf();
    const result = await form.submit<MessageResponse>(passwordRoutes.forgot());

    if (result) {
        sent.value = result.message;
    }
}
</script>

<template>
    <el-card shadow="never">
        <template #header>
            <span class="text-lg font-medium">Восстановление пароля</span>
        </template>

        <el-result
            v-if="sent"
            icon="success"
            title="Проверьте почту"
            :sub-title="sent"
        />

        <el-form v-else label-position="top" @submit.prevent="submit">
            <p class="mb-4 text-sm text-[var(--el-text-color-secondary)]">
                Укажите e-mail аккаунта — пришлём ссылку, по которой можно
                задать новый пароль.
            </p>
            <el-form-item label="Email" :error="form.errors.email">
                <el-input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    autofocus
                />
            </el-form-item>
            <el-button
                type="primary"
                native-type="submit"
                class="w-full"
                :loading="form.processing"
            >
                Отправить ссылку
            </el-button>
        </el-form>

        <div class="mt-4 text-center text-sm">
            <router-link
                :to="{ name: 'login' }"
                class="text-[var(--el-color-primary)]"
            >
                Вернуться ко входу
            </router-link>
        </div>
    </el-card>
</template>
