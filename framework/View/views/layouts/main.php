<?php

declare(strict_types=1);

/**
 * Каркас всех страниц: шапка, меню, сообщения и стили.
 *
 * Лежит в ядре, но перекрывается приложением: положите свой файл в
 * resources/views/layouts/main.php — View возьмёт его.
 *
 * @var string $content
 * @var string $title
 * @var array<int, array{message: string, type: string}> $flash
 * @var string $active
 * @var \Rsgrinko\Proton\Access\Viewer $viewer
 * @var bool $bare страница входа — без шапки и меню
 */

use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

$bare = $bare ?? false;
$menu = (array) Config::get('menu', []);
$name = (string) Config::get('app.name', 'Proton');

// Число у ссылки «Уведомления»: один COUNT на страницу, и только вошедшему
$unread = $viewer->isGuest() ? 0 : UserNotification::unreadFor($viewer->id());
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — <?= View::e($name) ?></title>
    <style>
        :root {
            /* Свои части полей и скроллбары браузер рисует по этой подсказке;
               без неё в тёмной теме они остаются светлыми */
            color-scheme: light;
            --bg: #f5f6f8;
            --panel: #ffffff;
            --border: #e2e5ea;
            --text: #1d2129;
            --muted: #6b7280;
            --accent: #2563eb;
            --ok: #15803d;
            --ok-bg: #dcfce7;
            --warn: #b45309;
            --warn-bg: #fef3c7;
            --err: #b91c1c;
            --err-bg: #fee2e2;
            --info-bg: #dbeafe;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --bg: #14161a;
                --panel: #1c1f25;
                --border: #2c313a;
                --text: #e5e7eb;
                --muted: #9ca3af;
                --accent: #60a5fa;
                --ok: #4ade80;
                --ok-bg: #14321f;
                --warn: #fbbf24;
                --warn-bg: #3a2d0c;
                --err: #f87171;
                --err-bg: #3b1717;
                --info-bg: #17293f;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        header {
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            padding: 0 20px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .brand { display: flex; align-items: baseline; gap: 12px; padding: 14px 0 10px; }
        .brand b { font-size: 17px; }
        .brand span { color: var(--muted); font-size: 12px; }
        .brand .who { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .brand .who form { display: inline; }

        /* Кнопка меню нужна только на узких экранах, разворачивает её чекбокс без единой строки JS */
        .burger { display: none; }

        .auth { max-width: 400px; margin: 8vh auto 0; }
        .auth h1 { text-align: center; }

        nav { display: flex; gap: 4px; flex-wrap: wrap; }
        nav a { padding: 8px 12px; border-radius: 6px 6px 0 0; color: var(--text); font-weight: 500; }
        nav a:hover { background: var(--bg); text-decoration: none; }
        nav a.active { background: var(--accent); color: #fff; }

        main { padding: 20px; max-width: 1400px; margin: 0 auto; }

        h1 { font-size: 20px; margin: 0 0 16px; }
        h2 { font-size: 16px; margin: 0 0 12px; }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .card > h2:first-child { margin-top: 0; }

        .grid { display: grid; gap: 16px; }
        /* Без этого широкая таблица распирает колонку вместо того, чтобы прокручиваться */
        .grid > * { min-width: 0; }
        .grid.cols-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .grid.cols-4 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }

        .stat .value { font-size: 26px; font-weight: 600; }
        .stat .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }

        a.card { color: inherit; }
        a.card:hover { text-decoration: none; border-color: var(--accent); }
        a.card:hover .value { color: var(--accent); }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        tr:last-child td { border-bottom: none; }
        .table-wrap { overflow-x: auto; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge.ok { background: var(--ok-bg); color: var(--ok); }
        .badge.warn { background: var(--warn-bg); color: var(--warn); }
        .badge.error { background: var(--err-bg); color: var(--err); }
        .badge.info { background: var(--info-bg); color: var(--accent); }
        .badge.muted { background: var(--border); color: var(--muted); }

        /* Перечислять типы полей по одному — значит рано или поздно забыть
           очередной (так поле type=url осталось без рамки): стилизуем всё,
           кроме того, что рисуется само — галочек, кнопок и выбора файла */
        input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=range]):not([type=color]),
        select, textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg);
            color: var(--text);
            font: inherit;
        }

        textarea { min-height: 140px; font-family: ui-monospace, Consolas, monospace; font-size: 13px; }

        label { display: block; margin-bottom: 12px; }
        label > span { display: block; margin-bottom: 4px; color: var(--muted); font-size: 12px; }
        /* Галочка с подписью — в строку; отдельной строкой их ставит только .row */
        label.inline { display: flex; align-items: center; gap: 8px; margin: 0; }
        label.inline > span { margin: 0; color: var(--text); font-size: 14px; }

        button, .btn {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--panel);
            color: var(--text);
            font: inherit;
            cursor: pointer;
        }

        button.copy { padding: 2px 8px; font-size: 12px; color: var(--muted); vertical-align: middle; }
        button.copy:hover { color: var(--text); }

        button:hover, .btn:hover { border-color: var(--accent); text-decoration: none; }
        button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.danger, .btn.danger { color: var(--err); }
        button:disabled, .btn:disabled { cursor: not-allowed; opacity: .6; }

        /* Всё, по чему можно щёлкнуть, должно говорить об этом курсором */
        a, select, summary, input[type=checkbox], input[type=radio], input[type=submit],
        input[type=file], label.inline, label.inline > span, .burger, .pagination a { cursor: pointer; }

        .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .row.end { justify-content: flex-end; }
        .spacer { flex: 1; }

        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end; }
        .filters label { margin: 0; }
        .filters .range { display: flex; gap: 6px; }
        .filters .range input { min-width: 0; }
        .filter-actions { margin-top: 12px; }

        .chart { display: flex; align-items: flex-end; gap: 2px; height: 120px; margin: 12px 0 6px; }
        .chart .col { flex: 1 1 0; min-width: 2px; min-height: 2px; border-radius: 3px 3px 0 0; background: var(--accent); opacity: .85; }
        .chart .col:hover { opacity: 1; }
        .chart .col.empty { background: var(--border); }
        .chart-legend { display: flex; justify-content: space-between; }

        /* Развёрнутый контекст лога бывает одной строкой в десятки килобайт:
           без ограничения он растягивает колонку и уносит вёрстку всей таблицы */
        /* Колонки лога фиксированы: иначе развёрнутый контекст перетягивает
           ширину на себя и время с уровнем сжимаются в столбик по букве */
        table.logs th:nth-child(1), table.logs td:nth-child(1) { width: 150px; }
        table.logs th:nth-child(2), table.logs td:nth-child(2) { width: 100px; }
        table.logs th:nth-child(3), table.logs td:nth-child(3) { width: 110px; }

        .log-context { display: block; max-width: min(100%, 900px); }
        .log-context pre { max-width: 100%; margin: 6px 0 0; overflow-x: auto; word-break: break-all; }

        .attachments { display: flex; flex-wrap: wrap; gap: 12px; }
        .attachments .attachment { width: 140px; }
        .attachments img { width: 140px; height: 100px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); }
        .attachments .file-icon { display: flex; align-items: center; justify-content: center; width: 140px; height: 100px;
                                  border: 1px solid var(--border); border-radius: 6px; color: var(--muted); font-size: 12px; }

        .comments .comment { padding: 10px 0; border-bottom: 1px solid var(--border); }
        .comments .comment:last-child { border-bottom: none; }
        .comments .comment p { margin: 6px 0 0; }
        .comments textarea { min-height: 70px; font-family: inherit; font-size: 14px; }

        .bulk-bar { margin-bottom: 10px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; }

        /* Панель отладки: видна только при APP_DEBUG и не мешает содержимому */
        .profiler { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; max-height: 60vh; overflow-y: auto;
                    background: var(--panel); border-top: 1px solid var(--border); padding: 6px 12px; font-size: 12px; }
        .profiler summary { cursor: pointer; color: var(--muted); }
        .profiler .warn { color: var(--warn); }
        .profiler h3 { font-size: 12px; margin: 10px 0 4px; }
        .profiler td { padding: 2px 6px; border: none; vertical-align: top; }
        .profiler td.count { white-space: nowrap; color: var(--muted); }

        a.sort { color: inherit; text-decoration: none; white-space: nowrap; }
        a.sort:hover { text-decoration: underline; }
        a.sort .arrow { margin-left: 4px; color: var(--accent); }

        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; word-break: break-word; }
        .flash.ok { background: var(--ok-bg); color: var(--ok); }
        .flash.error { background: var(--err-bg); color: var(--err); }

        /* Плашка «вход под пользователем»: сверху шапки, чтобы не спутать
           с обычным флеш-сообщением и не потерять при прокрутке */
        .impersonating { background: var(--warn-bg); color: var(--warn); padding: 6px 20px;
                          display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; }
        .impersonating form { display: inline; }
        .impersonating button { padding: 3px 10px; font-size: 12px; }

        .muted { color: var(--muted); }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
        .nowrap { white-space: nowrap; }
        .break { word-break: break-all; white-space: normal; }
        /* В pre переносы значимы: иначе .break склеивает команду в одну строку */
        pre.break { white-space: pre-wrap; }
        .small { font-size: 12px; }
        .right { text-align: right; }

        pre {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            overflow-x: auto;
            font-size: 12px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
        }

        dl.props { display: grid; grid-template-columns: 220px 1fr; gap: 6px 16px; margin: 0; }
        dl.props dt { color: var(--muted); }
        dl.props dd { margin: 0; word-break: break-word; }

        .pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
        .pagination a, .pagination span { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; }
        .pagination .current { background: var(--accent); border-color: var(--accent); color: #fff; }

        .checks .item { display: flex; gap: 10px; align-items: baseline; padding: 7px 0; border-bottom: 1px solid var(--border); }
        .checks .item:last-child { border-bottom: none; }
        .checks .item .title { min-width: 200px; }
        .checks .item .hint { color: var(--muted); font-size: 12px; }

        /* Планшеты: таблицы ужимаются за счёт второстепенных колонок */
        @media (max-width: 900px) {
            .hide-sm { display: none; }
        }

        /* Телефоны и узкие окна */
        @media (max-width: 760px) {
            header { padding: 0 12px; }
            .brand { padding: 10px 0; gap: 8px; }
            .brand .tagline { display: none; }
            .brand .who { margin-left: 0; gap: 8px; }

            /* Меню прячем под кнопку: десяток пунктов в строку не помещается */
            .burger {
                display: inline-flex;
                margin-left: auto;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 34px;
                border: 1px solid var(--border);
                border-radius: 6px;
                font-size: 18px;
                line-height: 1;
                user-select: none;
            }

            .menu-toggle:checked ~ .brand .burger { background: var(--accent); border-color: var(--accent); color: #fff; }

            nav { display: none; }
            .menu-toggle:checked ~ nav { display: flex; flex-direction: column; gap: 2px; padding-bottom: 10px; }
            nav a { border-radius: 6px; padding: 10px 12px; }

            main { padding: 12px; }
            h1 { font-size: 18px; }

            .card { padding: 12px; }
            .grid { gap: 12px; }
            .grid.cols-2 { grid-template-columns: 1fr; }
            .grid.cols-4 { grid-template-columns: 1fr 1fr; }
            .stat .value { font-size: 22px; }

            .filters { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; }
            .row > label { min-width: 100%; }

            th, td { padding: 6px 8px; }

            /* Скрытые колонки не должны всплыть из-за display:block у ячеек */
            table.list td.hide-sm { display: none; }

            /* Списки разворачиваем в карточки: иначе колонки сжимаются в столбик из букв */
            table.list, table.list tbody, table.list tr, table.list td { display: block; width: auto; }
            table.list tr.head { display: none; }
            table.list tr { padding: 10px 0; border-bottom: 1px solid var(--border); }
            table.list tr.head + tr { padding-top: 0; }
            table.list tr:last-child { border-bottom: none; padding-bottom: 0; }
            table.list td { border: none; padding: 2px 0; }
            table.list td:empty { display: none; }

            dl.props { grid-template-columns: 1fr; gap: 0; }
            dl.props dt { margin-top: 10px; font-size: 12px; }
            dl.props dt:first-child { margin-top: 0; }

            .checks .item { flex-direction: column; gap: 2px; }
        }
    </style>
</head>
<body>
<?php if (!$bare && View::isImpersonating()) { ?>
    <div class="impersonating">
        Вы вошли как <b><?= View::e($viewer->name()) ?></b>, обычно — <?= View::e((string) View::impersonator()?->login) ?>.
        <form method="post" action="<?= View::e(View::route('admin.impersonate.stop')) ?>">
            <?= View::csrf() ?>
            <button type="submit">Вернуться в свою учётную запись</button>
        </form>
    </div>
<?php } ?>

<?php if (!$bare) { ?>
<header>
    <input type="checkbox" id="menu-toggle" class="menu-toggle" hidden>
    <div class="brand">
        <b><?= View::e($name) ?></b>
        <span class="tagline">на Proton</span>

        <?php if (!$viewer->isGuest()) { ?>
            <span class="who">
                <a class="muted small" href="<?= View::e(View::route('notifications')) ?>" title="Уведомления">
                    Уведомления<?= $unread > 0 ? ' <span class="badge warn">' . $unread . '</span>' : '' ?>
                </a>
                <a class="muted small" href="<?= View::e(View::route('profile')) ?>"><?= View::e($viewer->name()) ?></a>
                <form method="post" action="<?= View::e(View::route('logout')) ?>">
                    <?= View::csrf() ?>
                    <button type="submit">Выйти</button>
                </form>
            </span>
        <?php } else { ?>
            <span class="who">
                <a href="<?= View::e(View::route('login')) ?>">Войти</a>
            </span>
        <?php } ?>

        <label class="burger" for="menu-toggle" title="Меню" aria-label="Меню">☰</label>
    </div>
    <nav>
        <?php foreach ($menu as $item) { ?>
            <?php if (($item['permission'] ?? '') === '' || View::can((string) $item['permission'])) { ?>
                <a href="<?= View::e(View::route((string) $item['route'])) ?>"
                   class="<?= $active === (string) $item['key'] ? 'active' : '' ?>"><?= View::e((string) $item['label']) ?></a>
            <?php } ?>
        <?php } ?>
    </nav>
</header>
<?php } ?>

<main<?= $bare ? ' class="auth"' : '' ?>>
    <?php foreach ($flash as $item) { ?>
        <div class="flash <?= View::e($item['type']) ?>"><?= View::e($item['message']) ?></div>
    <?php } ?>

    <?= $content ?>
</main>

<?= View::partial('profiler') ?>

<?php /*
    Единственный скрипт: кнопка «скопировать» и галочка «отметить все». Без
    буфера обмена браузера первую не сделать никак, а копировать ключ руками
    приходится каждый раз. Обработчик один на страницу и висит на документе.
    Всё остальное работает и без JavaScript.
*/ ?>
<script>
    document.addEventListener('change', function (event) {
        var master = event.target.closest('[data-check-all]');

        if (!master) {
            return;
        }

        var form = master.closest('form');

        if (!form) {
            return;
        }

        Array.prototype.forEach.call(form.querySelectorAll('[data-check-item]'), function (box) {
            box.checked = master.checked;
        });

        countChecked(form);
    });

    // Счётчик отмеченного: без него непонятно, над сколькими записями
    // сработает массовое действие
    document.addEventListener('change', function (event) {
        var box = event.target.closest('[data-check-item]');

        if (box) {
            countChecked(box.closest('form'));
        }
    });

    function countChecked(form) {
        if (!form) {
            return;
        }

        var label = form.querySelector('[data-check-count]');

        if (!label) {
            return;
        }

        var count = form.querySelectorAll('[data-check-item]:checked').length;

        label.textContent = count === 0 ? 'ничего не отмечено' : 'отмечено: ' + count;
    }

    // Опасное действие переспрашивает: массовое удаление промахом мыши
    // не отменить
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-confirm]');

        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');

        if (!button) {
            return;
        }

        var text = button.getAttribute('data-copy');
        var done = function () {
            var was = button.textContent;
            button.textContent = 'скопировано';
            setTimeout(function () { button.textContent = was; }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }

        // Запасной путь для http и старых браузеров
        var field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        try {
            document.execCommand('copy');
            done();
        } finally {
            document.body.removeChild(field);
        }
    });
</script>
</body>
</html>
