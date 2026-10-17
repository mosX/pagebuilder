<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import invitations from '@/routes/api/invitations';
import { useSessionStore } from '@/stores/session';
import type { Session } from '@/types';

type Info = {
    company: string;
    email: string;
    role: string;
    invited_by: string | null;
    expired: boolean;
    account_exists: boolean;
    signed_in_as: string | null;
};

/**
 * Accepting an invitation to a company. The invited address decides the account:
 * a signed-in user with that e-mail joins at once, a new person creates an account
 * for it, and an existing account has to sign in first.
 */
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const token = String(route.params.token);

const info = ref<Info | null>(null);
const missing = ref(false);
const loading = ref(true);

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

onMounted(async () => {
    try {
        info.value = await send<Info>(invitations.show(token));
    } catch {
        missing.value = true;
    } finally {
        loading.value = false;
    }
});

const sameAccount = () =>
    !!info.value?.signed_in_as &&
    info.value.signed_in_as.toLowerCase() === info.value.email;

async function accept() {
    await session.prepareCsrf();
    const result = await form.submit<Session>(invitations.accept(token));

    if (result) {
        session.set(result);
        ElMessage.success(
            `Вы присоединились к компании «${info.value?.company}».`,
        );
        await router.push('/admin');
    } else {
        form.reset('password', 'password_confirmation');
    }
}

async function switchAccount() {
    await session.logout();
    await router.push({ name: 'login', query: { redirect: route.fullPath } });
}
</script>

<template>
    <el-card v-loading="loading" shadow="never">
        <template #header>
            <span class="text-lg font-medium">Приглашение в компанию</span>
        </template>

        <el-result
            v-if="missing"
            icon="warning"
            title="Приглашение не найдено"
            sub-title="Ссылка уже использована или приглашение отозвано. Попросите прислать новое."
        />

        <template v-else-if="info">
            <p class="mb-4">
                <template v-if="info.invited_by"
                    >{{ info.invited_by }} приглашает вас</template
                >
                <template v-else>Вас приглашают</template>
                в компанию <b>«{{ info.company }}»</b> с ролью
                <b>«{{ info.role }}»</b>.
            </p>

            <el-alert
                v-if="info.expired"
                type="error"
                :closable="false"
                title="Срок приглашения истёк. Попросите прислать новое."
            />

            <template v-else-if="info.signed_in_as">
                <template v-if="sameAccount()">
                    <el-button
                        type="primary"
                        class="w-full"
                        :loading="form.processing"
                        @click="accept"
                    >
                        Принять приглашение
                    </el-button>
                </template>
                <template v-else>
                    <el-alert
                        type="warning"
                        :closable="false"
                        class="mb-4"
                        :title="`Вы вошли как ${info.signed_in_as}, а приглашение отправлено на ${info.email}.`"
                    />
                    <el-button class="w-full" @click="switchAccount">
                        Войти под {{ info.email }}
                    </el-button>
                </template>
            </template>

            <template v-else-if="info.account_exists">
                <p class="mb-4 text-sm text-[var(--el-text-color-secondary)]">
                    У адреса {{ info.email }} уже есть аккаунт. Войдите в него,
                    чтобы принять приглашение.
                </p>
                <el-button
                    type="primary"
                    class="w-full"
                    @click="
                        router.push({
                            name: 'login',
                            query: { redirect: route.fullPath },
                        })
                    "
                >
                    Войти
                </el-button>
            </template>

            <el-form v-else label-position="top" @submit.prevent="accept">
                <el-form-item label="Email">
                    <el-input :model-value="info.email" disabled />
                </el-form-item>
                <el-form-item label="Ваше имя" :error="form.errors.name">
                    <el-input v-model="form.name" autocomplete="name" />
                </el-form-item>
                <el-form-item label="Пароль" :error="form.errors.password">
                    <el-input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        show-password
                    />
                </el-form-item>
                <el-form-item label="Пароль ещё раз">
                    <el-input
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        show-password
                    />
                </el-form-item>
                <p
                    v-if="form.errors.invitation"
                    class="mb-3 text-sm text-[var(--el-color-danger)]"
                >
                    {{ form.errors.invitation }}
                </p>
                <el-button
                    type="primary"
                    native-type="submit"
                    class="w-full"
                    :loading="form.processing"
                >
                    Создать аккаунт и присоединиться
                </el-button>
            </el-form>

            <p
                v-if="form.errors.invitation && info.signed_in_as"
                class="mt-3 text-sm text-[var(--el-color-danger)]"
            >
                {{ form.errors.invitation }}
            </p>
        </template>
    </el-card>
</template>
