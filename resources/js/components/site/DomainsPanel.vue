<script setup lang="ts">
import { CopyDocument, Delete, Plus, Refresh } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { isValidationError, send } from '@/lib/http';
import domainsApi from '@/routes/api/domains';
import type { MessageResponse } from '@/types';

type Domain = {
    id: number;
    host: string;
    verified: boolean;
    is_primary: boolean;
    txt_name: string;
    txt_value: string;
    points_here: boolean | null;
    checked_at: string | null;
};

type DomainsResponse = {
    domains: Domain[];
    target: string;
    site_url: string;
    max: number;
};

/**
 * The company's own domains: DNS records to set up, ownership check, primary domain.
 */
defineProps<{ canEdit: boolean }>();

const data = ref<DomainsResponse | null>(null);
const loading = ref(true);
const checking = ref<number | null>(null);
const form = useForm({ host: '' });

async function load() {
    loading.value = true;

    try {
        data.value = await send<DomainsResponse>(domainsApi.index());
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function add() {
    const result = await form.submit<MessageResponse>(domainsApi.store());

    if (result) {
        form.reset();
        ElMessage.success(result.message);
        await load();
    }
}

async function verify(domain: Domain) {
    checking.value = domain.id;

    try {
        const result = await send<MessageResponse & { domain: Domain }>(
            domainsApi.verify(domain.id),
        );
        ElMessage.success(result.message);
        Object.assign(domain, result.domain);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.warning(error.response?.data.message ?? '');
        }
    } finally {
        checking.value = null;
    }

    await load();
}

async function action(request: Promise<MessageResponse>) {
    try {
        ElMessage.success((await request).message);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(error.response?.data.message ?? '');
        }
    }

    await load();
}

async function copy(text: string) {
    await navigator.clipboard.writeText(text);
    ElMessage.success('Скопировано');
}
</script>

<template>
    <div v-loading="loading" class="flex flex-col gap-4">
        <el-card shadow="never">
            <p class="mb-4 text-sm text-[var(--el-text-color-secondary)]">
                Сайт уже работает на
                <a :href="data?.site_url" target="_blank" class="underline">{{
                    data?.site_url
                }}</a
                >. Подключите свой домен — после подтверждения сайт будет
                открываться на нём, а старый адрес перенаправит туда.
            </p>
            <form
                v-if="canEdit"
                class="flex flex-wrap items-start gap-2"
                @submit.prevent="add"
            >
                <el-form-item :error="form.errors.host" class="!mb-0">
                    <el-input
                        v-model="form.host"
                        placeholder="acme.com или www.acme.com"
                        class="!w-72"
                    />
                </el-form-item>
                <el-button
                    type="primary"
                    native-type="submit"
                    :icon="Plus"
                    :loading="form.processing"
                    :disabled="
                        !form.host ||
                        (data?.domains.length ?? 0) >= (data?.max ?? 0)
                    "
                >
                    Подключить домен
                </el-button>
            </form>
        </el-card>

        <el-card
            v-for="domain in data?.domains ?? []"
            :key="domain.id"
            shadow="never"
        >
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-base font-medium">{{ domain.host }}</span>
                <el-tag v-if="domain.is_primary" type="success">
                    Основной
                </el-tag>
                <el-tag v-else-if="domain.verified" type="info">
                    Перенаправляет на основной
                </el-tag>
                <el-tag v-else type="warning">Не подтверждён</el-tag>
                <span class="flex-1" />
                <template v-if="canEdit">
                    <el-button
                        :icon="Refresh"
                        size="small"
                        :loading="checking === domain.id"
                        @click="verify(domain)"
                    >
                        Проверить
                    </el-button>
                    <el-button
                        v-if="domain.verified && !domain.is_primary"
                        size="small"
                        @click="action(send(domainsApi.primary(domain.id)))"
                    >
                        Сделать основным
                    </el-button>
                    <el-popconfirm
                        :title="`Отключить ${domain.host}?`"
                        confirm-button-text="Отключить"
                        cancel-button-text="Отмена"
                        width="240"
                        @confirm="action(send(domainsApi.destroy(domain.id)))"
                    >
                        <template #reference>
                            <el-button
                                :icon="Delete"
                                size="small"
                                type="danger"
                                plain
                            />
                        </template>
                    </el-popconfirm>
                </template>
            </div>

            <p class="mb-2 text-sm">
                Добавьте у регистратора домена две DNS-записи:
            </p>
            <el-table
                :data="[
                    {
                        type: 'CNAME',
                        name: domain.host,
                        value: `${data?.target}.`,
                        note: 'направляет домен на сайт (для корневого домена — ALIAS/ANAME или A-запись на IP сервера)',
                    },
                    {
                        type: 'TXT',
                        name: domain.txt_name,
                        value: domain.txt_value,
                        note: 'подтверждает, что домен ваш',
                    },
                ]"
                size="small"
            >
                <el-table-column prop="type" label="Тип" width="80" />
                <el-table-column label="Имя" min-width="200">
                    <template #default="{ row }">
                        <code>{{ row.name }}</code>
                    </template>
                </el-table-column>
                <el-table-column label="Значение" min-width="280">
                    <template #default="{ row }">
                        <div class="flex items-center gap-1">
                            <code class="break-all">{{ row.value }}</code>
                            <el-button
                                :icon="CopyDocument"
                                link
                                @click="copy(row.value)"
                            />
                        </div>
                        <div
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ row.note }}
                        </div>
                    </template>
                </el-table-column>
            </el-table>
            <p
                v-if="domain.points_here === false"
                class="mt-2 text-sm text-[var(--el-color-warning)]"
            >
                Домен пока не указывает на сайт: проверьте CNAME-запись.
            </p>
        </el-card>
    </div>
</template>
