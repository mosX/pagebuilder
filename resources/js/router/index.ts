import { ElMessage } from 'element-plus';
import { createRouter, createWebHistory } from 'vue-router';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { useSessionStore } from '@/stores/session';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            component: AuthLayout,
            children: [
                { path: '', redirect: '/admin' },
                {
                    path: 'login',
                    name: 'login',
                    component: () => import('@/pages/auth/Login.vue'),
                    meta: { guest: true, title: 'Вход' },
                },
                {
                    path: 'register',
                    name: 'register',
                    component: () => import('@/pages/auth/Register.vue'),
                    meta: { guest: true, title: 'Регистрация' },
                },
                {
                    // Open to guests and signed-in users: the page decides how to accept.
                    path: 'invite/:token',
                    name: 'invitation',
                    component: () => import('@/pages/auth/Invitation.vue'),
                    meta: { title: 'Приглашение' },
                },
                {
                    path: 'forgot-password',
                    name: 'password.forgot',
                    component: () => import('@/pages/auth/ForgotPassword.vue'),
                    meta: { guest: true, title: 'Восстановление пароля' },
                },
                {
                    path: 'reset-password/:token',
                    name: 'password.reset',
                    component: () => import('@/pages/auth/ResetPassword.vue'),
                    meta: { guest: true, title: 'Новый пароль' },
                },
                {
                    path: 'onboarding',
                    name: 'onboarding',
                    component: () =>
                        import('@/pages/onboarding/CreateCompany.vue'),
                    meta: { auth: true, title: 'Создание компании' },
                },
            ],
        },
        {
            path: '/admin',
            component: AdminLayout,
            meta: { auth: true, tenant: true },
            children: [
                {
                    path: '',
                    name: 'dashboard',
                    component: () => import('@/pages/admin/Dashboard.vue'),
                    meta: { title: 'Дашборд' },
                },
                {
                    path: 'media',
                    name: 'media',
                    component: () => import('@/pages/admin/media/Index.vue'),
                    meta: { title: 'Медиатека', permission: 'content.view' },
                },
                {
                    path: 'patterns',
                    name: 'patterns',
                    component: () => import('@/pages/admin/patterns/Index.vue'),
                    meta: { title: 'Секции', permission: 'content.view' },
                },
                {
                    path: 'patterns/:id',
                    name: 'patterns.edit',
                    component: () =>
                        import('@/pages/admin/content/BlocksEditor.vue'),
                    meta: { title: 'Секция', permission: 'content.manage' },
                },
                {
                    path: 'collections',
                    name: 'collections',
                    component: () =>
                        import('@/pages/admin/collections/Index.vue'),
                    meta: { title: 'Коллекции', permission: 'content.view' },
                },
                {
                    path: 'collections/:name/schema',
                    name: 'collections.schema',
                    component: () =>
                        import('@/pages/admin/collections/Schema.vue'),
                    meta: {
                        title: 'Структура коллекции',
                        permission: 'schema.manage',
                    },
                },
                {
                    path: 'collections/:name/template',
                    name: 'collections.template',
                    component: () =>
                        import('@/pages/admin/content/BlocksEditor.vue'),
                    meta: {
                        title: 'Шаблон страницы записи',
                        permission: 'schema.manage',
                    },
                },
                {
                    path: 'content/:name',
                    name: 'records',
                    component: () =>
                        import('@/pages/admin/content/Records.vue'),
                    meta: { title: 'Записи', permission: 'content.view' },
                },
                {
                    path: 'content/:name/:id/blocks/:field',
                    name: 'blocks.editor',
                    component: () =>
                        import('@/pages/admin/content/BlocksEditor.vue'),
                    meta: {
                        title: 'Конструктор',
                        permission: 'content.manage',
                    },
                },
                {
                    path: 'site',
                    name: 'site',
                    component: () => import('@/pages/admin/site/Index.vue'),
                    meta: { title: 'Сайт', permission: 'content.view' },
                },
                {
                    path: 'site/:part(header|footer|not-found)',
                    name: 'site.layout',
                    component: () =>
                        import('@/pages/admin/content/BlocksEditor.vue'),
                    meta: {
                        title: 'Шапка и подвал',
                        permission: 'content.manage',
                    },
                },
                {
                    path: 'stats',
                    name: 'stats',
                    component: () => import('@/pages/admin/stats/Index.vue'),
                    meta: { title: 'Статистика', permission: 'content.view' },
                },
                {
                    path: 'visitors',
                    name: 'site-members',
                    component: () =>
                        import('@/pages/admin/site-members/Index.vue'),
                    meta: { title: 'Посетители', permission: 'content.view' },
                },
                {
                    path: 'automations',
                    name: 'automations',
                    component: () =>
                        import('@/pages/admin/automations/Index.vue'),
                    meta: {
                        title: 'Автоматизации',
                        permission: 'schema.manage',
                    },
                },
                {
                    path: 'webhooks',
                    name: 'webhooks',
                    component: () => import('@/pages/admin/webhooks/Index.vue'),
                    meta: { title: 'Вебхуки', permission: 'schema.manage' },
                },
                {
                    path: 'api-keys',
                    name: 'api-keys',
                    component: () => import('@/pages/admin/api-keys/Index.vue'),
                    meta: { title: 'API-ключи', permission: 'api.manage' },
                },
                {
                    path: 'activity',
                    name: 'activity',
                    component: () => import('@/pages/admin/activity/Index.vue'),
                    meta: {
                        title: 'Журнал действий',
                        permission: 'audit.view',
                    },
                },
                {
                    path: 'members',
                    name: 'members',
                    component: () => import('@/pages/admin/members/Index.vue'),
                    meta: { title: 'Пользователи', permission: 'members.view' },
                },
                {
                    path: 'company',
                    name: 'company',
                    component: () => import('@/pages/admin/company/Edit.vue'),
                    meta: { title: 'Компания', permission: 'company.view' },
                },
                {
                    path: 'profile',
                    name: 'profile',
                    component: () => import('@/pages/admin/profile/Edit.vue'),
                    meta: { title: 'Профиль' },
                },
                {
                    path: 'super/tenants',
                    name: 'super.tenants',
                    component: () =>
                        import('@/pages/admin/super/tenants/Index.vue'),
                    meta: { title: 'Все компании', superAdmin: true },
                },
            ],
        },
        { path: '/:pathMatch(.*)*', redirect: '/admin' },
    ],
});

router.beforeEach(async (to) => {
    const session = useSessionStore();
    await session.load();

    if (to.meta.guest && session.user) {
        return { name: 'dashboard' };
    }

    if (to.meta.auth && !session.user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.name === 'onboarding' && session.tenant) {
        return { name: 'dashboard' };
    }

    if (to.meta.tenant && !session.tenant) {
        return { name: 'onboarding' };
    }

    const forbidden =
        (to.meta.superAdmin && !session.isSuperAdmin) ||
        (to.meta.permission && !session.can(to.meta.permission));

    if (forbidden) {
        ElMessage.error('Недостаточно прав для этого раздела.');

        return { name: 'dashboard' };
    }
});

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} - ${appName}` : appName;
});
