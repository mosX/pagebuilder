const CYRILLIC: Record<string, string> = {
    а: 'a',
    б: 'b',
    в: 'v',
    г: 'g',
    д: 'd',
    е: 'e',
    ё: 'e',
    ж: 'zh',
    з: 'z',
    и: 'i',
    й: 'y',
    к: 'k',
    л: 'l',
    м: 'm',
    н: 'n',
    о: 'o',
    п: 'p',
    р: 'r',
    с: 's',
    т: 't',
    у: 'u',
    ф: 'f',
    х: 'h',
    ц: 'ts',
    ч: 'ch',
    ш: 'sh',
    щ: 'sch',
    ъ: '',
    ы: 'y',
    ь: '',
    э: 'e',
    ю: 'yu',
    я: 'ya',
    і: 'i',
    ї: 'yi',
    є: 'ye',
    ґ: 'g',
};

/**
 * Turns a human label into a system name: lowercase latin snake_case,
 * starting with a letter, at most `maxLength` characters ("Цена товара" → "tsena_tovara").
 */
export function toIdentifier(label: string, maxLength = 24): string {
    const latin = label
        .toLowerCase()
        .replace(/[а-яёіїєґ]/g, (char) => CYRILLIC[char] ?? '');

    return latin
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .slice(0, maxLength)
        .replace(/_+$/, '');
}
