<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { useRouter } from 'vue-router';
import TemplatePicker from '@/components/TemplatePicker.vue';
import { useForm } from '@/composables/useForm';
import { create } from '@/routes/api/tenants';
import { useSessionStore } from '@/stores/session';
import type { Session } from '@/types';

/**
 * Creating one more company from the admin, from a starter or a saved template.
 */
const open = defineModel<boolean>({ required: true });
const session = useSessionStore();
const router = useRouter();

const form = useForm({ name: '', template: 'business' });

async function submit() {
    const result = await form.submit<Session>(create());

    if (result) {
        session.set(result);
        open.value = false;
        form.reset();
        ElMessage.success('Компания создана.');
        await router.push({ name: 'dashboard' });
    }
}
</script>

<template>
    <el-dialog v-model="open" title="Новая компания" width="560" append-to-body>
        <el-form label-position="top" @submit.prevent="submit">
            <el-form-item label="Название компании" :error="form.errors.name">
                <el-input v-model="form.name" />
            </el-form-item>
            <el-form-item label="Шаблон сайта" :error="form.errors.template">
                <TemplatePicker v-if="open" v-model="form.template" />
            </el-form-item>
        </el-form>
        <template #footer>
            <el-button @click="open = false">Отмена</el-button>
            <el-button
                type="primary"
                :loading="form.processing"
                @click="submit"
            >
                Создать
            </el-button>
        </template>
    </el-dialog>
</template>
