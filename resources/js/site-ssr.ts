/// <reference types="node" />
import { createServer } from 'node:http';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import SiteApp from '@/site/SiteApp.vue';
import type { SitePayload } from '@/site/types';

/**
 * SSR server for company sites: Laravel POSTs a page payload, gets HTML back.
 * Build: `npm run build:ssr`; run: `node bootstrap/ssr/site-ssr.js`.
 * Listens on 127.0.0.1 only — it must never be exposed publicly.
 */
const port = Number(process.env.SITE_SSR_PORT ?? 13714);
const maxBody = 5 * 1024 * 1024;

const server = createServer((request, response) => {
    if (request.method !== 'POST' || request.url !== '/render') {
        response.writeHead(404).end();

        return;
    }

    let body = '';

    request.on('data', (chunk: Buffer) => {
        body += chunk;

        if (body.length > maxBody) {
            response.writeHead(413).end();
            request.destroy();
        }
    });

    request.on('end', async () => {
        try {
            const payload = JSON.parse(body) as SitePayload;
            const html = await renderToString(createSSRApp(SiteApp, payload));

            response.writeHead(200, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify({ html }));
        } catch (error) {
            console.error(error);
            response.writeHead(500, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify({ error: 'render_failed' }));
        }
    });
});

server.listen(port, '127.0.0.1', () => {
    console.log(`Site SSR server listening on http://127.0.0.1:${port}/render`);
});
