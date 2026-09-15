<?php

declare(strict_types=1);

/**
 * Входящие вебхуки: кого мы готовы слушать.
 *
 * Адрес источника — /api/v1/hooks/{ключ}, метод POST или GET. Проверка одна:
 * токен. Отправитель присылает его заголовком `X-Proton-Token`, через
 * `Authorization: Bearer …` или параметром `token` — как умеет. Сам токен лежит
 * в .env (или в окружении процесса), здесь только её имя: секрету в репозитории
 * не место.
 *
 * Обработчик получает разобранное тело (или параметры адреса у GET) и сам
 * запрос. Отвечать надо быстро: отправитель ждёт ответа и повторит посылку,
 * если мы задумаемся. Поэтому всё долгое — письма, обращения к чужим API,
 * пересчёты — уходит в очередь, а обработчик только проверяет данные.
 *
 * Ниже три примера: с токеном и очередью, без токена и эхо для проверки связи.
 */

use App\Jobs\RebuildNoteSlugsJob;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Webhooks\Incoming;

/**
 * Биллинг: оплата заказа. Токен обязателен, работа уходит в очередь.
 *
 *     POST /api/v1/hooks/billing
 *     X-Proton-Token: <значение переменной BILLING_HOOK_TOKEN>
 *     Content-Type: application/json
 *
 *     {
 *         "event": "order.paid",
 *         "id": 10231,
 *         "amount": 4500.00,
 *         "currency": "RUB",
 *         "customer": {"email": "ivan@example.com", "name": "Иван"}
 *     }
 */
Incoming::register('billing', 'Биллинг', 'BILLING_HOOK_TOKEN', static function (array $payload, Request $request): void {
    // Чужая система шлёт всё подряд: берём только то, что нам интересно
    if ((string) ($payload['event'] ?? '') !== 'order.paid') {
        return;
    }

    $orderId = (int) ($payload['id'] ?? 0);

    if ($orderId <= 0) {
        // Исключение попадёт в журнал посылки, а отправитель получит 202:
        // повторять ему не нужно, разберёмся сами
        throw new ProtonException('В посылке нет номера заказа');
    }

    // Долгую работу делает очередь, здесь только ставим задачу
    Queue::push(RebuildNoteSlugsJob::class, ['order' => $orderId], 0, null, 10);

    // И разносим событие внутри приложения: на него могут быть подписаны свои
    // обработчики и исходящие вебхуки
    Events::fire('billing.order.paid', ['order' => $orderId, 'payload' => $payload]);
});

/**
 * Почтовый сервис: отчёты о доставке. Токен присылать умеют не все, поэтому
 * здесь его нет — адрес открыт всякому, кто его знает.
 *
 *     GET /api/v1/hooks/mail?event=mail.bounced&email=ivan@example.com&reason=mailbox+not+found
 *
 * Раз проверки нет, обработчик не делает ничего необратимого: только отметка
 * в логе и событие.
 */
Incoming::register('mail', 'Почтовый сервис', '', static function (array $payload): void {
    $email = trim((string) ($payload['email'] ?? ''));

    if ($email === '') {
        return;
    }

    (new Logger('webhooks'))->warning('Письмо не дошло', [
        'event'  => (string) ($payload['event'] ?? ''),
        'email'  => $email,
        'reason' => (string) ($payload['reason'] ?? ''),
    ]);

    Events::fire('mail.bounced', ['email' => $email, 'payload' => $payload]);
});

/**
 * Эхо для проверки связи: ничего не делает, только пишет в лог. Удобно, когда
 * настраиваешь источник и хочешь убедиться, что токен доходит.
 *
 *     GET /api/v1/hooks/demo?event=ping&token=<значение переменной DEMO_HOOK_TOKEN>
 */
Incoming::register('demo', 'Проверка связи', 'DEMO_HOOK_TOKEN', static function (array $payload, Request $request): void {
    (new Logger('webhooks'))->info('Пришёл проверочный вебхук', [
        'event'   => (string) ($payload['event'] ?? ''),
        'ip'      => $request->ip(),
        'payload' => $payload,
    ]);
});
