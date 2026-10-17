# Деплой Pagebuilder

Инструкция для первого запуска на сервере и для последующих выкладок.

## Что нужно на сервере

- PHP 8.4 + FPM с расширениями `pdo_mysql`, `mbstring`, `intl`, `gd`, `redis`, `bcmath`, `zip`.
- MySQL 8 (или MariaDB 10.11+), Redis, Node.js 22+, Composer, nginx, supervisor.
- DNS: запись A для `pagebuilder.example` и wildcard-запись `*.pagebuilder.example` на этот сервер.
- TLS: wildcard-сертификат на `pagebuilder.example` и `*.pagebuilder.example`. Let's Encrypt выдаёт его только через DNS-проверку (`certbot --dns-<провайдер>`).

## Структура каталогов

```
/var/www/pagebuilder/
├── releases/<время>/   каждая выкладка
├── shared/.env         настройки (общие для всех выкладок)
├── shared/storage/     загрузки, логи, кэш файлов
└── current -> releases/<последняя>
```

## Первый запуск

1. Создать каталоги и пользователя БД:
    ```bash
    mkdir -p /var/www/pagebuilder/{releases,shared/storage}
    mysql -e "CREATE DATABASE pagebuilder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
              CREATE USER 'pagebuilder'@'localhost' IDENTIFIED BY '…';
              GRANT ALL ON pagebuilder.* TO 'pagebuilder'@'localhost';"
    ```
    Пользователю БД нужны права на `CREATE`, `ALTER`, `DROP` и `INDEX`: коллекции компаний — это настоящие таблицы.
2. Скопировать `deploy/.env.production.example` в `shared/.env` и заполнить всё, что помечено `change`.
3. Создать структуру `shared/storage` (`app/public`, `framework/{cache,sessions,views}`, `logs`) и выдать права пользователю `www-data`.
4. Выполнить `deploy/deploy.sh main` (переменная `REPO` — адрес репозитория).
5. Один раз:
    ```bash
    cd /var/www/pagebuilder/current
    php artisan key:generate          # если APP_KEY ещё пуст; затем повторить php artisan optimize
    php artisan db:seed --class=SuperAdminSeeder
    ```
6. nginx: `deploy/nginx.conf.example` → `/etc/nginx/sites-available/pagebuilder`, заменить домен и пути, `nginx -t && systemctl reload nginx`.
7. supervisor: `deploy/supervisor.conf.example` → `/etc/supervisor/conf.d/pagebuilder.conf`, затем `supervisorctl reread && supervisorctl update`.

## Выкладка обновлений

```bash
deploy/deploy.sh main
```

Скрипт:

- собирает выкладку в новом каталоге;
- ставит зависимости и собирает фронтенд (`npm run build:ssr`);
- выполняет миграции и `tenants:provision-pages`;
- собирает кэши (`php artisan optimize`);
- атомарно переключает `current`, перезапускает очередь и SSR.

Откат — переключить `current` на предыдущий каталог из `releases/` и перезапустить сервисы. Миграции при этом не откатываются.

## Процессы

| Процесс                          | Зачем                          | Если не запущен           |
| -------------------------------- | ------------------------------ | ------------------------- |
| `queue:work`                     | вебхуки, автоматизации, письма | события копятся в очереди |
| `node bootstrap/ssr/site-ssr.js` | HTML сайтов на сервере (SEO)   | сайты рисуются в браузере |
| `schedule:work` (или cron)       | чистка журнала действий        | журнал растёт             |

SSR-сервер слушает только `127.0.0.1:13714`, открывать его наружу нельзя.

## Кэш страниц

- Страницы сайтов отдаются гостям из кэша (Redis).
- Любое изменение компании сразу сбрасывает все её страницы. Изменения через админку, API, формы, автоматизации и публикации делают это сами.
- Вошедшие посетители, страницы поиска и кабинета, редиректы и 404 не кэшируются.
- Заголовок ответа `X-Site-Cache: hit|miss` показывает, откуда пришла страница.
- Выключить кэш — `SITE_PAGE_CACHE=false`.
- Если данные правились прямо в базе, в обход приложения, сбросить кэш целиком: `php artisan cache:clear`.

## Свои домены компаний (acme.com)

Компания подключает домен в «Сайт» → «Домены» и добавляет у регистратора две записи:

- **CNAME** `acme.com` → `acme.pagebuilder.example.` — направляет трафик на сервер. Для корневого домена, где CNAME нельзя, — ALIAS/ANAME или A-запись на IP сервера.
- **TXT** `_pagebuilder.acme.com` → код из админки — подтверждает владение доменом.

После «Проверить» сайт открывается на домене. Поддомен и остальные домены компании перенаправляют на основной (301), а превью `/site/{slug}` остаётся на платформе.

HTTPS для таких доменов выпускает Caddy по первому запросу — см. `deploy/Caddyfile.example`. Перед выпуском он спрашивает приложение (`/internal/tls-check`, отвечает только локальным запросам), так что сертификаты выдаются только для подтверждённых доменов. nginx в такой схеме слушает `127.0.0.1:8080` без TLS, а Caddy стоит перед ним.

## Безопасность в продакшне

- `APP_DEBUG=false`, `APP_ENV=production`. В продакшне также включаются строгие правила паролей для админов и запрет разрушительных команд БД.
- `SESSION_DOMAIN` пустой: cookie админки не уходят на поддомены компаний.
- `SITE_PREVIEW_PATH=false`: превью `/site/{slug}` на домене админки перенаправляет на настоящий адрес сайта, так что контент компаний никогда не выполняется рядом с сессией админки. Включайте только при разработке без wildcard DNS.
- `MAX_COMPANIES_PER_USER`: сколько компаний может создать один пользователь (каждая — это настоящие таблицы в БД). На супер-админов лимит не действует.
- Приложение само ставит заголовки CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, а при HTTPS — HSTS. Для `/storage` и `/build` заголовки задаёт nginx (см. конфиг).
- Резервные копии: база (`mysqldump --single-transaction`) и `shared/storage/app/public` (загрузки).
