import { createApp, createSSRApp } from 'vue';
import SiteApp from '@/site/SiteApp.vue';
import type { SitePayload } from '@/site/types';

/**
 * Browser entry of company sites. The page payload is embedded by Laravel
 * (resources/views/site.blade.php); server-rendered markup is hydrated,
 * otherwise the page renders here.
 */
declare global {
    interface Window {
        __SITE__: SitePayload;
    }
}

const el = document.getElementById('site');

if (el) {
    const create = el.dataset.ssr === '1' ? createSSRApp : createApp;
    create(SiteApp, { ...window.__SITE__ }).mount(el);
}
