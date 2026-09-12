<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\UserNotification;

/**
 * Лента уведомлений: своя у каждого.
 *
 * Права тут не нужно — человек смотрит собственные уведомления; чужие для него
 * просто не существуют, потому что фильтр по владельцу уходит в SQL.
 */
final class NotificationsController extends Controller
{
    public function index(Request $request, Viewer $viewer): Response
    {
        $onlyUnread = $request->query('unread', '') !== '';

        $query = UserNotification::query()
            ->where('user_id', $viewer->id())
            ->when($onlyUnread, static function ($query): void {
                $query->whereNull('read_at');
            })
            ->orderBy('id', 'desc');

        return $this->view('notifications', [
            'active' => 'notifications',
            'page'   => $query->paginate($this->page($request), $this->perPage()),
            'unread' => UserNotification::unreadFor($viewer->id()),
            'filter' => $onlyUnread,
        ], 'Уведомления');
    }

    /**
     * Отметить одно прочитанным и уйти по ссылке уведомления, если она есть.
     */
    public function read(int $id, Viewer $viewer): Response
    {
        $notification = $this->own($id, $viewer);

        $notification->markRead();

        $url = trim((string) $notification->raw('url'));

        return $url !== '' ? Response::redirect($url) : $this->redirect('notifications');
    }

    public function readAll(Viewer $viewer): Response
    {
        $count = UserNotification::markAllRead($viewer->id());

        $this->flash($count > 0 ? 'Прочитано: ' . $count : 'Непрочитанных не было');

        return $this->redirect('notifications');
    }

    public function delete(int $id, Viewer $viewer): Response
    {
        $this->own($id, $viewer)->delete();

        $this->flash('Уведомление убрано');

        return $this->redirect('notifications');
    }

    /**
     * Своё уведомление; чужое — «не найдено», а не «нет доступа».
     */
    private function own(int $id, Viewer $viewer): UserNotification
    {
        /** @var UserNotification|null $notification */
        $notification = UserNotification::query()
            ->where('id', $id)
            ->where('user_id', $viewer->id())
            ->first();

        /** @var UserNotification $found */
        $found = $this->require($notification, 'notifications', 'Уведомление не найдено');

        return $found;
    }
}
