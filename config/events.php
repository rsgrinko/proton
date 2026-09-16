<?php

declare(strict_types=1);

/**
 * Подписки на события. Здесь видно сразу все — искать их по всему приложению
 * не нужно.
 *
 * Событие объявляется через Events::fire('имя', ['ключ' => значение]),
 * фильтр — через Events::filter('имя', $значение).
 *
 * Свои события ядра:
 *   user.registered   — завели пользователя (регистрация или админ)
 *   user.login        — вошёл
 *   user.logout       — вышел
 *   mail.sending      — письмо уходит (вернуть false, чтобы отменить)
 *   mail.sent         — письмо ушло
 *   mail.failed       — письмо не ушло
 *   mail.driver       — фильтр: чем отправлять письма
 *   model.saving      — любая модель перед save() (и создание, и правка; вернуть false — отменить)
 *   model.creating    — модель перед первой записью (вернуть false — отменить)
 *   model.updating    — модель перед обновлением, несёт changes (вернуть false — отменить)
 *   model.created     — модель только что появилась в базе
 *   model.updated     — модель только что обновилась
 *   model.saved       — после model.created или model.updated
 *   model.deleting    — модель перед delete()/forceDelete() (вернуть false — отменить)
 *   model.deleted     — модель удалена (мягко или насовсем — смотри payload['force'])
 *   model.restoring   — модель перед restore() (вернуть false — отменить)
 *   model.restored    — модель восстановлена из корзины
 *
 * Событие одно на все модели — слушатель узнаёт свою по
 * `$payload['model'] instanceof \App\Models\Note`. Подробности и полный пример
 * с отменой сохранения — docs/DATABASE.md, раздел «События модели».
 */

use App\Models\Note;
use App\Notifications\NoteCreatedNotification;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Webhooks\Webhooks;

// События из реестра config/webhooks.php уезжают подписчикам сами
Webhooks::subscribe();

// Пример: отмечаем в логе каждого нового пользователя
Events::listen('user.registered', static function (array $payload): void {
    $user = $payload['user'] ?? null;

    if ($user !== null) {
        (new Logger('auth'))->info('Регистрация', ['login' => (string) $user->login]);
    }
});

// Пример своего уведомления: строка в ленте у тех, кто видит чужие данные,
// на каждую новую заметку. Реального раздела так шумно уведомлять не стоит —
// это образец связки «событие -> Notify», см. app/Notifications/NoteCreatedNotification.php
Events::listen('note.created', static function (array $payload): void {
    if ($payload['note'] instanceof Note) {
        Notify::toPermission(Permission::DATA_ALL, new NoteCreatedNotification($payload['note']));
    }
});

/*
 * Так подменяется способ отправки писем, не трогая места отправки:
 *
 * Events::listen('mail.driver', static function ($driver) {
 *     return new \App\Mail\MyDriver();
 * });
 */

/*
 * Модельные события летят для всех моделей разом — своя проверяется через
 * instanceof. Так сбрасывают именной кэш запроса (Query::remember($сек, $ключ))
 * сразу после того, как данные, из которых он посчитан, изменились:
 *
 * Events::listen('model.saved', static function (array $payload): void {
 *     if ($payload['model'] instanceof \App\Models\Note) {
 *         Cache::forget('query:notes:count:get');
 *     }
 * });
 * Events::listen('model.deleted', static function (array $payload): void {
 *     if ($payload['model'] instanceof \App\Models\Note) {
 *         Cache::forget('query:notes:count:get');
 *     }
 * });
 *
 * А так — отменяют сохранение, если оно не годится (не заменяет Validator
 * и Policy, это для сквозных проверок, которым всё равно, из какого контроллера
 * пришли):
 *
 * Events::listen('model.creating', static function (array $payload): bool|void {
 *     if ($payload['model'] instanceof \App\Models\Note && trim((string) $payload['model']->title) === '') {
 *         return false;
 *     }
 * });
 */

/*
 * Событие приложения уезжает подписчикам, как только объявлено реестром
 * config/webhooks.php, — отдельная подписка для этого не нужна:
 *
 * Events::fire('note.created', ['note' => $note]);
 */
