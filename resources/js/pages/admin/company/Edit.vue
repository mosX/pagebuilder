<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import company from '@/routes/api/company';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse } from '@/types';

type Company = {
    id: number;
    name: string;
    slug: string;
    site_url: string;
    created_at: string;
};

const session = useSessionStore();
const loading = ref(true);
const canUpdate = ref(false);
// The site lives on {slug}{suffix}; both parts come from the server-built URL.
const siteScheme = ref('https://');
const siteSuffix = ref('');

const form = useForm({
    name: '',
    slug: '',
});

onMounted(async () => {
    const result = await send<{ company: Company; can_update: boolean }>(
        company.show(),
    );

    form.name = result.company.name;
    form.slug = result.company.slug;
    form.defaults();
    canUpdate.value = result.can_update;

    const url = new URL(result.company.site_url);
    siteScheme.value = `${url.protocol}//`;
    siteSuffix.value = url.host.slice(result.company.slug.length);
    loading.value = false;
});

async function submit() {
    const result = await form.submit<MessageResponse>(company.update());

    if (result) {
        form.defaults();
        ElMessage.success(result.message);
        // Company name is shown in the header and switcher.
        await session.load(true);
    }
}
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold">Настройки компании</h1>

        <el-card v-loading="loading" shadow="never" class="max-w-2xl">
            <el-form
                label-position="top"
                :disabled="!canUpdate"
                @submit.prevent="submit"
            >
                <el-form-item label="Название" :error="form.errors.name">
                    <el-input v-model="form.name" />
                </el-form-item>
                <el-form-item label="Адрес сайта" :error="form.errors.slug">
                    <el-input v-model="form.slug">
                        <template #prepend>{{ siteScheme }}</template>
                        <template #append>{{ siteSuffix }}</template>
                    </el-input>
                    <div class="text-xs text-[var(--el-text-color-secondary)]">
                        Поддомен сайта компании: латиница, цифры и дефисы.
                    </div>
                </el-form-item>
                <el-button
                    v-if="canUpdate"
                    type="primary"
                    native-type="submit"
                    :loading="form.processing"
                    :disabled="!form.isDirty"
                >
                    Сохранить
                </el-button>
            </el-form>
        </el-card>
    </div>
</template>
