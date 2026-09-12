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
 *   user.registered  — завели пользователя (регистрация или админ)
 *   user.login       — вошёл
 *   user.logout      — вышел
 *   mail.sending     — письмо уходит (вернуть false, чтобы отменить)
 *   mail.sent        — письмо ушло
 *   mail.failed      — письмо не ушло
 *   mail.driver      — фильтр: чем отправлять письма
 */

use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Support\Logger;

// Пример: отмечаем в логе каждого нового пользователя
Events::listen('user.registered', static function (array $payload): void {
    $user = $payload['user'] ?? null;

    if ($user !== null) {
        (new Logger('auth'))->info('Регистрация', ['login' => (string) $user->login]);
    }
});

/*
 * Так подменяется способ отправки писем, не трогая места отправки:
 *
 * Events::listen('mail.driver', static function ($driver) {
 *     return new \App\Mail\MyDriver();
 * });
 */
