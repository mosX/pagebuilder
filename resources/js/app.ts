import ElementPlus from 'element-plus';
import ru from 'element-plus/es/locale/lang/ru';
import 'element-plus/dist/index.css';
import { createPinia } from 'pinia';
import { createApp } from 'vue';
import App from '@/App.vue';
import { setHttpHandlers } from '@/lib/http';
import { router } from '@/router';
import { useSessionStore } from '@/stores/session';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(ElementPlus, { locale: ru });
app.directive('focus', {
    mounted: (el: HTMLElement, shouldFocus) => {
        if (shouldFocus.value !== false) {
            el.focus();
        }
    },
});

setHttpHandlers({
    onUnauthenticated: () => {
        useSessionStore(pinia).set(null);

        // The initial /api/me probe for guests also returns 401; the router guard handles that case.
        if (router.currentRoute.value.meta.auth) {
            void router.push({ name: 'login' });
        }
    },
    onNoTenant: () => {
        void useSessionStore(pinia)
            .load(true)
            .then(() => router.push({ name: 'onboarding' }));
    },
});

app.mount('#app');
