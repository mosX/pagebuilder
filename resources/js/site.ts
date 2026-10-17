import { createApp, createSSRApp } from 'vue';
import SiteApp from '@/site/SiteApp.vue';
import type { SitePayload } from '@/site/types';

/**
 * Browser entry of company sites. The page payload is embedded by Laravel as a JSON
 * data block (resources/views/site.blade.php); server-rendered markup is hydrated,
 * otherwise the page renders here.
 */
const el = document.getElementById('site');
const data = document.getElementById('site-payload')?.textContent;

if (el && data) {
    const payload = JSON.parse(data) as SitePayload;
    const create = el.dataset.ssr === '1' ? createSSRApp : createApp;
    create(SiteApp, { ...payload }).mount(el);
}
