# Выкладка и обслуживание

Боевой набор: nginx + php-fpm на точку входа `public/index.php` и воркер отдельной
службой. Больше ничего не нужно — ни composer, ни сборки.

## Что выложить

Всё, кроме `var/` и `.env`: они свои на каждом сервере. Каталог `var/` должен быть
доступен на запись пользователю php-fpm и воркера — туда идут логи, кэш, загруженные
файлы и база, если она SQLite.

Права: наружу смотрит только `public/`. Всё остальное — код, `.env`, `var/` — лежит
выше корня сайта и по HTTP не отдаётся.

## Установка на сервере

```bash
php bin/proton install --db=mysql --url=https://example.com --admin=admin
```

Установщик запишет `.env`, создаст ключ, накатит схему и заведёт администратора.
Веб-мастер (`/install`) делает то же самое, но требует, чтобы сайт уже отвечал;
на боевом сервере обычно проще консолью. После установки `/install` закрывается сам.

На бою обязательно `APP_ENV=production` и `APP_DEBUG=false`: иначе тексты ошибок
видны посторонним. `APP_URL` должен совпадать с настоящим адресом — по нему
собираются ссылки в письмах.

## nginx

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;

    root /var/www/proton/public;
    index index.php;

    client_max_body_size 16m;   # не меньше FILES_MAX_SIZE

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Ни база, ни логи, ни конфиги наружу не смотрят
    location ~ /\.(env|git) { deny all; }
}
```

Если сайт стоит за прокси или балансировщиком, пропишите его адреса в
`TRUSTED_PROXIES`: только тогда `X-Forwarded-For` считается настоящим адресом
клиента. Пустой список означает «верить только прямому соединению» — по умолчанию
так и есть, и это правильно: иначе адрес подделает кто угодно.

## Воркер

Без запущенного воркера очередь копится, письма не уходят, расписание не идёт и
уборка не делается.

```ini
# /etc/systemd/system/proton-worker.service
[Unit]
Description=Proton worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/proton
ExecStart=/usr/bin/php /var/www/proton/bin/proton worker
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now proton-worker
```

Без ключей воркер разбирает общую очередь и очередь вебхуков. Когда посылки
подписчикам лучше развести с письмами по разным процессам, поднимается вторая
служба с тем же файлом и `ExecStart=… worker --queue=webhooks`, а первой ставится
`--queue=default`.

Где systemd нет, тот же круг делает cron:

```cron
* * * * * cd /var/www/proton && php bin/proton worker --once >> /dev/null 2>&1
```

**После каждой выкладки кода воркер нужно перезапускать** — он держит загруженные
классы в памяти, поэтому php-fpm уже отвечает по-новому, а задачи выполняются старым
кодом. По-хорошему это делает `php bin/proton worker:restart`: воркер доработает круг
и выйдет, а служба поднимет его заново с новым кодом.

## Порядок выкладки

```bash
git pull
php bin/proton migrate            # схема
php bin/proton cache:clear        # кэш мог запомнить старое
php bin/proton worker:restart     # воркер должен подхватить новый код
php bin/proton status             # убедиться, что всё на месте
```

Миграции меняют боевую схему, а откат удаляет колонки вместе с данными — это крайняя
мера, а не обычный шаг. Порядок и правила — в [MIGRATIONS.md](MIGRATIONS.md).

## Что проверять потом

`php bin/proton status` — одна команда на всё: версия PHP и расширения, права на
запись, место на диске, база и непринятые миграции, ключ приложения, режим отладки,
адрес приложения, очередь с упавшими задачами, последний круг воркера и почта. Строки
с пометкой требуют внимания, внизу — что именно сделать.

Остальное по месту:

```bash
php bin/proton queue:status --failed   # что не доехало
php bin/proton mail:test you@example.com --queue
php bin/proton route:list              # карта адресов
```

## Логи и уборка

Логи — `var/log/app-ГГГГ-ММ-ДД.log`: время, уровень, канал, сообщение, контекст JSON,
всегда одной строкой. Уровень задаёт `LOG_LEVEL`, срок хранения — `LOG_KEEP_DAYS`.

Чистит за собой воркер: старые логи, выполненные задачи (`QUEUE_KEEP_DAYS`),
просроченный кэш, токены «запомнить меня», сеансы (`AUTH_SESSIONS_KEEP_DAYS`) и журнал
действий (`AUDIT_KEEP_DAYS`). Отдельного cron на уборку не нужно — нужен работающий
воркер.

## Время и ключ

Часовой пояс (`APP_TIMEZONE`) обязан быть один у php-fpm, воркера и консольных команд:
запуск команды из другого пояса пишет отложенным задачам неправильное время, и они
уходят раньше срока.

`APP_KEY` на живом проекте менять нельзя: им зашифрованы секреты в базе и подписаны
куки. Сменили — расшифровка ломается, все сеансы становятся недействительными.
Ключ живёт в `.env` и в репозиторий не попадает.

## Резервные копии

Копировать нужно две вещи: базу (`mysqldump` или файл `var/app.sqlite`) и `var/storage`
с загруженными файлами. `.env` стоит хранить отдельно и надёжно — без `APP_KEY`
зашифрованные значения из копии базы не прочитать.
