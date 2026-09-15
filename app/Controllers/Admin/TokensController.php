<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\IpAllowlist;
use Rsgrinko\Proton\View\View;

/**
 * Ключи доступа к API: выпуск, ограничение по адресам, отключение.
 *
 * Целиком ключ виден один раз — сразу после выпуска: в базе лежит только хеш,
 * и показать его повторно неоткуда.
 */
final class TokensController extends Controller
{
    public function index(Request $request): Response
    {
        $owners = [];

        foreach (User::all() as $user) {
            $owners[$user->id()] = (string) $user->login;
        }

        $filters = $this->filters($request, [
            Filter::search('q', 'Поиск', ['name'], 'название ключа'),
            Filter::select('user_id', 'Владелец', $owners),
            Filter::flag('active', 'Действует'),
        ])->sortable(['id', 'name', 'last_used_at'], 'id');

        return $this->view('admin/tokens', [
            'active'  => 'tokens',
            'page'    => $filters->apply(ApiToken::query())->paginate($this->page($request), $this->perPage()),
            'owners'  => $owners,
            'users'   => User::query()->where('active', 1)->orderBy('login')->get(),
            'filters' => $filters,
            // Свежий ключ, если только что выпустили
            'issued'  => (string) View::takeStash('api_key', ''),
        ], 'Ключи API');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'    => 'nullable|max:191',
            'user_id' => 'required|integer|exists:users',
            'ips'     => 'nullable|max:500',
            'days'    => 'nullable|integer',
        ], ['name' => 'Название', 'user_id' => 'Владелец', 'ips' => 'Разрешённые адреса', 'days' => 'Срок в днях']);

        $ips = trim((string) ($data['ips'] ?? ''));

        foreach (IpAllowlist::parse($ips) as $rule) {
            if (!IpAllowlist::valid($rule)) {
                $this->flash('Непонятная запись адреса: ' . $rule, 'error');

                return $this->redirect('admin.tokens');
            }
        }

        $days = max(0, (int) ($data['days'] ?? 0));

        $issued = ApiToken::issue((string) ($data['name'] ?? ''), (int) $data['user_id'], $ips, $days);

        Audit::created(
            'token',
            $issued['token']->id(),
            'выпущен ключ ' . $issued['token']->mask() . ($days > 0 ? ' на ' . $days . ' дн.' : ' без срока')
        );

        // Ключ показываем один раз — дальше только маска
        View::stash('api_key', $issued['key']);

        $this->flash('Ключ выпущен. Скопируйте его сейчас — больше он не появится');

        return $this->redirect('admin.tokens');
    }

    public function revoke(int $id): Response
    {
        /** @var ApiToken $token */
        $token = $this->require(ApiToken::find($id), 'admin.tokens', 'Ключ не найден');

        // Отключаем, а не удаляем: строка нужна, чтобы понимать, чем ходили раньше
        $token->forceFill(['active' => 0])->save();

        Audit::action('token', $token->id(), 'отключён ключ ' . $token->mask());

        $this->flash('Ключ отключён');

        return $this->redirect('admin.tokens');
    }

    public function delete(int $id): Response
    {
        /** @var ApiToken $token */
        $token = $this->require(ApiToken::find($id), 'admin.tokens', 'Ключ не найден');

        $mask = $token->mask();

        $token->delete();

        Audit::deleted('token', $id, 'удалён ключ ' . $mask);

        $this->flash('Ключ удалён');

        return $this->redirect('admin.tokens');
    }
}
