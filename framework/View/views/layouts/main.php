<?php

declare(strict_types=1);

/**
 * Каркас всех страниц: боковое меню, шапка страницы, сообщения и стили.
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
use Rsgrinko\Proton\Support\Profiler;
use Rsgrinko\Proton\View\View;

$bare = $bare ?? false;
$menu = (array) Config::get('menu', []);
$name = (string) Config::get('app.name', 'Proton');

// Число у ссылки «Уведомления»: один COUNT на страницу, и только вошедшему
$unread = $viewer->isGuest() ? 0 : UserNotification::unreadFor($viewer->id());

// Тема — своя у пользователя, у гостя всегда светлая: переключатель живёт
// в профиле, спросить там больше некого
$theme = (!$viewer->isGuest() && $viewer->user() !== null) ? $viewer->user()->theme() : 'light';
?>
<!doctype html>
<html lang="ru"<?= $theme === 'dark' ? ' data-theme="dark"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — <?= View::e($name) ?></title>
    <style>
        :root {
            /* Свои части полей и скроллбары браузер рисует по этой подсказке;
               без неё в тёмной теме они остаются светлыми */
            color-scheme: light;
            --bg: #f2f3f5;
            --panel: #ffffff;
            --border: #e3e5ea;
            --text: #23262d;
            --muted: #767b87;
            --accent: #3350b3;
            --ok: #157347;
            --ok-bg: #dcf3e8;
            --warn: #b45309;
            --warn-bg: #fef3c7;
            --err: #b42318;
            --err-bg: #fbe2df;
            --info-bg: #e7eaf8;
        }

        /* Тёмная тема — по выбору в профиле (атрибут на <html>), не по
           настройке ОС: иначе вид панели расходится с тем, что согласовали,
           у любого, чей браузер сам стоит в тёмном режиме */
        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #14161a;
            --panel: #1c1f25;
            --border: #2c313a;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #8b93e8;
            --ok: #4ade80;
            --ok-bg: #14321f;
            --warn: #fbbf24;
            --warn-bg: #3a2d0c;
            --err: #f87171;
            --err-bg: #3b1717;
            --info-bg: #232a4a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 12.5px/1.45 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Каркас: узкая колонка разделов слева, шапка страницы и контент
           справа. Пунктов в админке больше десятка — в один ряд наверху они
           не помещались и переносились на вторую строку; список сбоку
           тянется вниз, а не вширь */
        .shell { display: flex; align-items: stretch; }

        /* На больших экранах список разделов не уезжает вместе с контентом:
           повисает под шапкой и сам не выше окна, а прокручивается своей
           полосой, если пунктов вдруг станет больше, чем влезает по высоте */
        .side { width: 196px; flex: none; background: var(--panel); border-right: 1px solid var(--border); padding: 14px 8px; box-sizing: border-box; position: sticky; top: 0; align-self: flex-start; height: 100vh; overflow-y: auto; }

        .side .brand { display: flex; align-items: center; gap: 8px; padding: 2px 8px 14px; }
        .side .logo { width: 22px; height: 22px; border-radius: 6px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; flex: none; }
        .side .brand b { font-size: 13px; font-weight: 650; }

        .side nav { display: flex; flex-direction: column; gap: 1px; }
        .side nav a { display: block; padding: 5px 9px; border-radius: 5px; color: var(--text); font-weight: 500; font-size: 12.5px; }
        .side nav a:hover { background: var(--bg); text-decoration: none; }
        .side nav a.active { background: var(--accent); color: #fff; font-weight: 600; }

        /* Разделитель между группами меню ставится по числу group у
           соседних пунктов конфига, а не жёстко по разделам — новых
           пунктов станет больше, а разметка не изменится */
        .side .nav-sep { height: 1px; background: var(--border); margin: 8px 4px; }

        .content-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .topbar {
            height: 40px;
            flex: none;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            font-size: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .crumb { color: var(--muted); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .crumb a { color: var(--muted); }
        .crumb a:hover { color: var(--accent); }
        .crumb b { color: var(--text); font-weight: 600; }

        .topbar .who { margin-left: auto; display: flex; align-items: center; gap: 10px; color: var(--muted); }
        .topbar .who form { display: inline; }

        /* Кнопка меню нужна только на узких экранах, разворачивает боковой
           список через чекбокс без единой строки JS */
        .burger { display: none; }

        .auth { max-width: 400px; margin: 8vh auto 0; }
        .auth h1 { text-align: center; }

        /* Без потолка ширины: сайдбар и так задаёт свою колонку, а от
           центрированного блока с полями по бокам на широком мониторе
           контент выглядел зажатым в узкую полоску посередине */
        main { padding: 14px 20px; width: 100%; box-sizing: border-box; }

        h1 { font-size: 16px; margin: 0 0 10px; }
        h2 { font-size: 14px; margin: 0 0 10px; }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .card > h2:first-child { margin-top: 0; }

        /* Карточка показателя на обзоре — тоньше обычной и с цветной
           полосой слева; свой цвет карточка задаёт переменной --c инлайн,
           без неё полоса берёт цвет акцента */
        .card.stat { border-left: 3px solid var(--c, var(--accent)); padding: 8px 10px; }

        .grid { display: grid; gap: 8px; }
        /* Без этого широкая таблица распирает колонку вместо того, чтобы прокручиваться */
        .grid > * { min-width: 0; }
        .grid.cols-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .grid.cols-4 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }

        .stat .value { font-size: 19px; font-weight: 650; line-height: 1.1; }
        .stat .label { color: var(--muted); font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 4px; }

        a.card { color: inherit; }
        a.card:hover { text-decoration: none; border-color: var(--accent); }
        a.card:hover .value { color: var(--accent); }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 8px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 650; font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; }
        tr:last-child td { border-bottom: none; }
        /* Список построчно — почти любая таблица в панели: полоса через
           строку держит взгляд на нужной ячейке в плотной сетке */
        table.list tr:nth-child(even) { background: var(--bg); }
        /* Аватар (36px) выше строки текста рядом с ним — по верхнему краю
           это смотрится обрезанным, по центру строки — как обычная строка
           списка */
        table.list td { vertical-align: middle; }
        .table-wrap { overflow-x: auto; }

        .badge {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge.ok { background: var(--ok-bg); color: var(--ok); }
        .badge.warn { background: var(--warn-bg); color: var(--warn); }
        .badge.error { background: var(--err-bg); color: var(--err); }
        .badge.info { background: var(--info-bg); color: var(--accent); }
        .badge.muted { background: var(--border); color: var(--muted); }

        /* Фото профиля и заглушка того же размера — оба квадрат с
           border-radius: 50%, поэтому появление настоящего фото вместо
           инициалов (и наоборот, после удаления) никогда не сдвигает
           соседний текст: место под кружок зарезервировано всегда одно */
        .avatar, .avatar-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex: none;
            vertical-align: middle;
        }

        .avatar { object-fit: cover; background: var(--border); }

        .avatar-placeholder {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: hsl(var(--avatar-hue, 210), 45%, 38%);
            color: #fff;
            font-weight: 600;
            font-size: 15px;
            user-select: none;
        }

        .avatar.lg, .avatar-placeholder.lg { width: 96px; height: 96px; }
        .avatar-placeholder.lg { font-size: 36px; }

        /* В шапке аватар — только опознавательный знак рядом с именем, не
           витрина: своя, меньшая величина того же кружка */
        .who-name { display: inline-flex; align-items: center; gap: 6px; color: var(--text); font-weight: 600; }
        .who-name .avatar, .who-name .avatar-placeholder { width: 22px; height: 22px; font-size: 10px; }

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

        /* Обычному полю ширина 100% идёт, а полю выбора файла — нет: рядом с
           короткой кнопкой и текстом «файл не выбран» пустая рамка на всю
           строку смотрится дыркой. Врастяжку — только кнопка внутри инпута,
           сам контрол — по содержимому */
        input[type=file] {
            display: inline-flex;
            align-items: center;
            width: auto;
            max-width: 100%;
            padding: 4px;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--bg);
            color: var(--muted);
            font: inherit;
        }
        input[type=file]::file-selector-button {
            padding: 6px 12px;
            margin-right: 8px;
            border: none;
            border-radius: 4px;
            background: var(--panel);
            color: var(--text);
            font: inherit;
            cursor: pointer;
            box-shadow: 0 0 0 1px var(--border);
        }
        input[type=file]::file-selector-button:hover { background: var(--accent); color: #fff; box-shadow: 0 0 0 1px var(--accent); }

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

        button:hover, .btn:hover { background: var(--bg); border-color: var(--accent); text-decoration: none; }
        button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.primary:hover, .btn.primary:hover { filter: brightness(.92); }
        button.danger, .btn.danger { color: var(--err); }
        button.danger:hover, .btn.danger:hover { background: var(--err-bg); border-color: var(--err); }
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

        /* Линия по точкам без баров — своя ширина/высота не нужна, тянется
           на всю карточку: viewBox у svg уже задаёт масштаб */
        .sparkline { width: 100%; height: 120px; display: block; color: var(--accent); margin: 12px 0 6px; }

        /* Развёрнутый контекст лога бывает одной строкой в десятки килобайт:
           без ограничения он растягивает колонку и уносит вёрстку всей таблицы */
        /* Колонки лога фиксированы: иначе развёрнутый контекст перетягивает
           ширину на себя и время с уровнем сжимаются в столбик по букве */
        table.logs th:nth-child(1), table.logs td:nth-child(1) { width: 150px; }
        table.logs th:nth-child(2), table.logs td:nth-child(2) { width: 100px; }
        table.logs th:nth-child(3), table.logs td:nth-child(3) { width: 110px; }

        .log-context { display: block; max-width: min(100%, 900px); }
        .log-context pre { max-width: 100%; margin: 6px 0 0; overflow-x: auto; word-break: break-all; }

        /* Развёрнутый список изменений в строке журнала — без своего фона и
           рамки он сливался с полосой чётной строки списка */
        .diff { margin-top: 6px; background: var(--info-bg); border-radius: 6px; overflow: hidden; }
        .diff td { border-bottom: 1px solid var(--border); }
        .diff tr:last-child td { border-bottom: none; }

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

        /* Панель отладки: видна только при APP_DEBUG. Свёрнутая, она занимает
           только строку summary — но position:fixed есть у неё всегда, поэтому
           main.has-profiler ниже держит для этой строки готовое место, а не
           перекрывает последнюю строку таблицы или кнопку внизу страницы */
        .profiler { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; max-height: 60vh; overflow-y: auto;
                    background: var(--panel); border-top: 1px solid var(--border); padding: 6px 12px; font-size: 12px; }
        .profiler summary { cursor: pointer; color: var(--muted); }
        .profiler[open] summary { border-bottom: 1px solid var(--border); padding-bottom: 6px; margin-bottom: 6px; }
        .profiler .warn { color: var(--warn); }
        .profiler h3 { font-size: 12px; margin: 10px 0 4px; }
        .profiler table { margin-bottom: 4px; }
        .profiler td { padding: 2px 6px; border: none; vertical-align: top; }
        .profiler td.count { white-space: nowrap; color: var(--muted); }
        .profiler details { margin-top: 8px; }
        .profiler details summary { color: var(--text); }

        main.has-profiler { padding-bottom: 34px; }

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
            .shell { flex-direction: column; }

            /* Список разделов на телефоне не полоса слева, а выпадающая
               панель поверх контента: висит под шапкой независимо от того,
               насколько длинная страница внизу, и не заставляет сначала
               пролистать её до конца, чтобы увидеть меню */
            .side {
                display: none;
                position: fixed;
                top: 40px;
                left: 0;
                right: 0;
                bottom: 0;
                height: auto;
                z-index: 15;
                width: auto;
                border-right: none;
                border-top: 1px solid var(--border);
                box-shadow: 0 8px 24px rgba(0, 0, 0, .15);
                overflow-y: auto;
            }
            .menu-toggle:checked ~ .shell .side { display: block; }

            .topbar { padding: 0 12px; gap: 8px; }
            /* «Proton /» перед названием страницы съедает и так тесную
               строку — оставляем только само название */
            .crumb .crumb-root { display: none; }
            /* Длинное имя иначе переносится на вторую строку и ломает
               высоту шапки */
            .who-name-text { display: inline-block; max-width: 70px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }

            .burger {
                display: inline-flex;
                flex: none;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 28px;
                border: 1px solid var(--border);
                border-radius: 6px;
                font-size: 16px;
                line-height: 1;
                user-select: none;
            }

            .menu-toggle:checked ~ .shell .burger { background: var(--accent); border-color: var(--accent); color: #fff; }

            main { padding: 10px; }
            h1 { font-size: 15px; }

            .card { padding: 8px 10px; }
            .grid { gap: 8px; }
            .grid.cols-2 { grid-template-columns: 1fr; }
            .grid.cols-4 { grid-template-columns: 1fr 1fr; }
            .stat .value { font-size: 17px; }

            .filters { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; }
            .row > label { min-width: 100%; }
            /* Пара дат в одну колонку не влезает — калькулятор ширины съедает
               иконку и цифры года; отдаём фильтру дат всю ширину строки */
            .filters label.dates { grid-column: 1 / -1; }
            .filters .range input { min-width: 0; }

            th, td { padding: 4px 6px; }

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

            /* Шапка таблицы пропадает вместе со столбцами — голое число или
               слово без неё непонятно само по себе. Там, где это важно,
               ячейка несёт название колонки в data-label */
            table.list td[data-label]::before {
                content: attr(data-label) ": ";
                color: var(--muted);
            }

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

<?php $mainClass = trim(($bare ? 'auth ' : '') . (Profiler::enabled() ? 'has-profiler' : '')); ?>
<?php if (!$bare) { ?>
<input type="checkbox" id="menu-toggle" class="menu-toggle" hidden>
<div class="shell">
    <aside class="side">
        <div class="brand">
            <div class="logo"><?= View::e(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
            <b><?= View::e($name) ?></b>
        </div>

        <?php $prevGroup = null; ?>
        <nav>
        <?php foreach ($menu as $item) { ?>
            <?php if (($item['permission'] ?? '') === '' || View::can((string) $item['permission'])) { ?>
                <?php $group = $item['group'] ?? null; ?>
                <?php if ($prevGroup !== null && $group !== $prevGroup) { ?></nav><div class="nav-sep"></div><nav><?php } ?>
                <a href="<?= View::e(View::route((string) $item['route'])) ?>"
                   class="<?= $active === (string) $item['key'] ? 'active' : '' ?>"><?= View::e((string) $item['label']) ?></a>
                <?php $prevGroup = $group; ?>
            <?php } ?>
        <?php } ?>
        </nav>
    </aside>

    <div class="content-col">
        <div class="topbar">
            <label class="burger" for="menu-toggle" title="Меню" aria-label="Меню">☰</label>

            <div class="crumb">
                <a href="<?= View::e(View::route('home')) ?>" class="crumb-root"><?= View::e($name) ?></a><span class="crumb-root"> / </span><b><?= View::e($title) ?></b>
            </div>

            <?php if (!$viewer->isGuest()) { ?>
                <div class="who">
                    <a class="muted small" href="<?= View::e(View::route('notifications')) ?>" title="Уведомления">
                        Уведомления<?= $unread > 0 ? ' <span class="badge warn">' . $unread . '</span>' : '' ?>
                    </a>
                    <a class="small who-name" href="<?= View::e(View::route('profile')) ?>">
                        <?php if ($viewer->user() !== null) { ?><?= View::avatar($viewer->user()) ?><?php } ?>
                        <span class="who-name-text"><?= View::e($viewer->name()) ?></span>
                    </a>
                    <form method="post" action="<?= View::e(View::route('logout')) ?>">
                        <?= View::csrf() ?>
                        <button type="submit">Выйти</button>
                    </form>
                </div>
            <?php } else { ?>
                <div class="who">
                    <a href="<?= View::e(View::route('login')) ?>">Войти</a>
                </div>
            <?php } ?>
        </div>

        <main<?= $mainClass !== '' ? ' class="' . $mainClass . '"' : '' ?>>
            <?php foreach ($flash as $item) { ?>
                <div class="flash <?= View::e($item['type']) ?>"><?= View::e($item['message']) ?></div>
            <?php } ?>

            <?= $content ?>
        </main>
    </div>
</div>
<?php } else { ?>
<main<?= $mainClass !== '' ? ' class="' . $mainClass . '"' : '' ?>>
    <?php foreach ($flash as $item) { ?>
        <div class="flash <?= View::e($item['type']) ?>"><?= View::e($item['message']) ?></div>
    <?php } ?>

    <?= $content ?>
</main>
<?php } ?>

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
