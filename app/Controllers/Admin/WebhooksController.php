<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\View\View;
use Rsgrinko\Proton\Webhooks\Incoming;
use Rsgrinko\Proton\Webhooks\Webhooks;

/**
 * Вебхуки: куда стучаться, о чём и с каким секретом.
 *
 * Секрет виден владельцу вебхука всегда — им подписчик проверяет посылки,
 * и подсмотреть его больше негде. Заменить секрет можно, и старый перестаёт
 * подходить сразу.
 */
final class WebhooksController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request, [
            Filter::search('q', 'Поиск', ['name', 'url'], 'название или адрес'),
            Filter::flag('active', 'Включён'),
        ])->sortable(['id', 'name'], 'id');

        return $this->view('admin/webhooks', [
            'active'   => 'webhooks',
            'page'     => $filters->apply(Webhook::query())->paginate($this->page($request), $this->perPage()),
            'groups'   => Webhooks::groups(),
            'stats'    => WebhookDelivery::stats(),
            'filters'  => $filters,
            // Входящие — обратная сторона: эти посылки присылают нам
            'incoming' => IncomingHook::query()->orderBy('id', 'desc')->limit(20)->get(),
            'sources'  => Incoming::sources(),
        ], 'Вебхуки');
    }

    public function create(): Response
    {
        return $this->view('admin/webhook', [
            'active'     => 'webhooks',
            'webhook'    => new Webhook(),
            'groups'     => Webhooks::groups(),
            'deliveries' => ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 0],
            'fresh'      => '',
        ], 'Новый вебхук');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'   => 'nullable|max:191',
            'url'    => 'required|url|max:500',
            'secret' => 'nullable|max:191',
        ], ['name' => 'Название', 'url' => 'Адрес', 'secret' => 'Секрет']);

        $events = Webhooks::filter((array) $request->input('events', []));

        if ($events === []) {
            $this->flash('Выберите хотя бы одно событие', 'error');

            return $this->redirect('admin.webhooks.create');
        }

        $webhook = Webhook::add(
            (string) ($data['name'] ?? ''),
            (string) $data['url'],
            $events,
            (string) ($data['secret'] ?? '')
        );

        Audit::created('webhook', $webhook->id(), 'вебхук «' . (string) $webhook->name . '» на ' . (string) $webhook->url);

        $this->flash('Вебхук создан. Секрет пропишите у подписчика');

        return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
    }

    public function show(Request $request, int $id): Response
    {
        $webhook = $this->webhook($id);

        return $this->view('admin/webhook', [
            'active'     => 'webhooks',
            'webhook'    => $webhook,
            'groups'     => Webhooks::groups(),
            'deliveries' => WebhookDelivery::query()
                ->where('webhook_id', $webhook->id())
                ->orderBy('id', 'desc')
                ->paginate($this->page($request), $this->perPage()),
            // Новый секрет показываем один раз, сразу после замены
            'fresh'      => (string) View::takeStash('webhook_secret', ''),
        ], (string) $webhook->name);
    }

    public function update(Request $request, int $id): Response
    {
        $webhook = $this->webhook($id);

        $data = $this->validate($request, [
            'name' => 'nullable|max:191',
            'url'  => 'required|url|max:500',
        ], ['name' => 'Название', 'url' => 'Адрес']);

        $events = Webhooks::filter((array) $request->input('events', []));

        if ($events === []) {
            $this->flash('Выберите хотя бы одно событие', 'error');

            return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
        }

        $before = [
            'name'   => (string) $webhook->name,
            'url'    => (string) $webhook->url,
            'events' => implode(', ', $webhook->events()),
        ];

        $webhook->forceFill([
            'name'   => (string) ($data['name'] ?? '') !== '' ? (string) $data['name'] : (string) $webhook->name,
            'url'    => (string) $data['url'],
            'events' => json_encode($events, JSON_UNESCAPED_UNICODE),
        ])->save();

        Audit::updated('webhook', $webhook->id(), 'вебхук «' . (string) $webhook->name . '»', Audit::between($before, [
            'name'   => (string) $webhook->name,
            'url'    => (string) $webhook->url,
            'events' => implode(', ', $webhook->events()),
        ]));

        $this->flash('Вебхук сохранён');

        return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
    }

    /**
     * Включить или отключить вебхук.
     */
    public function toggle(int $id): Response
    {
        $webhook = $this->webhook($id);

        $active = !(bool) $webhook->active;

        // Включили заново — счётчик неудач больше не повод отключать
        $webhook->forceFill(['active' => $active ? 1 : 0, 'failures' => 0])->save();

        Audit::action('webhook', $webhook->id(), ($active ? 'включён' : 'отключён') . ' вебхук «' . (string) $webhook->name . '»');

        $this->flash($active ? 'Вебхук включён' : 'Вебхук отключён');

        return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
    }

    /**
     * Заменить секрет: старый перестаёт подходить сразу.
     */
    public function rotate(int $id): Response
    {
        $webhook = $this->webhook($id);

        View::stash('webhook_secret', $webhook->rotateSecret());

        Audit::action('webhook', $webhook->id(), 'заменён секрет вебхука «' . (string) $webhook->name . '»');

        $this->flash('Секрет заменён. Пропишите новый у подписчика — старый больше не подойдёт');

        return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
    }

    /**
     * Пробная посылка: уходит очередью, как и настоящие.
     */
    public function test(int $id): Response
    {
        $webhook = $this->webhook($id);

        $delivery = Webhooks::test($webhook);

        Audit::action('webhook', $webhook->id(), 'отправлена пробная посылка вебхуку «' . (string) $webhook->name . '»');

        $this->flash('Пробная посылка поставлена в очередь (№' . $delivery->id() . '). Её разберёт воркер');

        return $this->redirect('admin.webhooks.show', ['id' => $webhook->id()]);
    }

    public function delete(int $id): Response
    {
        $webhook = $this->webhook($id);

        $name = (string) $webhook->name;

        // Журнал доставок без вебхука не нужен
        WebhookDelivery::query()->where('webhook_id', $webhook->id())->delete();

        $webhook->delete();

        Audit::deleted('webhook', $id, 'удалён вебхук «' . $name . '»');

        $this->flash('Вебхук удалён');

        return $this->redirect('admin.webhooks');
    }

    private function webhook(int $id): Webhook
    {
        /** @var Webhook $webhook */
        $webhook = $this->require(Webhook::find($id), 'admin.webhooks', 'Вебхук не найден');

        return $webhook;
    }
}
