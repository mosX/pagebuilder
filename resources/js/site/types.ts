import type { BlockNode } from '@/blocks/registry';

export type SitePayload = {
    site: {
        name: string;
        slug: string;
        /** '' on the company subdomain, '/site/{slug}' on the preview path. */
        base_url: string;
        pages: { title: string; slug: string; url: string }[];
    };
    page: {
        title: string;
        slug: string;
        description: string | null;
        blocks: BlockNode[];
    } | null;
};
