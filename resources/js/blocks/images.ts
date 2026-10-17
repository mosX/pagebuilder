/**
 * Responsive images for files of the media library (/storage/…): the server serves
 * resized WebP copies at /img/{width}/{path}, generated on first request and cached.
 * Other URLs (external images) are used as they are.
 */
export const IMAGE_WIDTHS = [320, 640, 960, 1280, 1920];

const STORAGE_PREFIX = '/storage/';

export function srcset(src: string | null | undefined): string | undefined {
    if (!src || !src.startsWith(STORAGE_PREFIX)) {
        return undefined;
    }

    const path = src.slice(STORAGE_PREFIX.length);

    return IMAGE_WIDTHS.map((width) => `/img/${width}/${path} ${width}w`).join(
        ', ',
    );
}

/** A resized copy of a media library image, e.g. for small thumbnails. */
export function resized(src: string, width: number): string {
    return src.startsWith(STORAGE_PREFIX)
        ? `/img/${width}/${src.slice(STORAGE_PREFIX.length)}`
        : src;
}
