<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Devices;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserField;
use Rsgrinko\Proton\Models\UserFieldValue;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;
use Rsgrinko\Proton\View\View;

/**
 * Пользователи: список, карточка, заведение, правка, отключение и удаление.
 */
final class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $roles   = $this->roles();
        $filters = $this->listFilters($request, $roles);

        return $this->view('admin/users', [
            'active'  => 'users',
            'page'    => $filters->apply(User::query())->paginate($this->page($request), $this->perPage()),
            'roles'   => $roles,
            'filters' => $filters,
        ], 'Пользователи');
    }

    /**
     * Выгрузка списка: тот же отбор, что на экране.
     */
    public function export(Request $request): Response
    {
        $roles   = $this->roles();
        $filters = $this->listFilters($request, $roles);

        return $this->exportCsv($filters->apply(User::query()), [
            'id'            => 'ID',
            'login'         => 'Логин',
            'name'          => 'Имя',
            'email'         => 'Почта',
            'role'          => ['Роль', static fn (User $user): string => $roles[(int) $user->raw('role_id')] ?? ''],
            'active'        => ['Активен', static fn (User $user): string => $user->isActive() ? 'да' : 'нет'],
            'created_at'    => 'Заведён',
            'last_login_at' => 'Последний вход',
        ], 'users', 'user', 'admin.users', $request);
    }

    /**
     * Фильтры списка — общие для страницы и выгрузки.
     *
     * @param array<int, string> $roles
     */
    private function listFilters(Request $request, array $roles): Filters
    {
        return $this->filters($request, [
            Filter::search('q', 'Поиск', ['login', 'email', 'name'], 'логин, почта или имя'),
            Filter::select('role_id', 'Роль', $roles),
            Filter::flag('active', 'Активен'),
            Filter::dates('created', 'Заведён', 'created_at'),
        ])->sortable(['id', 'login', 'created_at', 'last_login_at'], 'id', 'asc');
    }

    public function create(): Response
    {
        return $this->view('admin/user', [
            'active' => 'users',
            'user'   => new User(),
            'roles'  => Role::all(),
            'devices' => [],
        ], 'Новый пользователь');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'login'    => 'required|min:3|max:100|regex:/^[A-Za-z0-9._-]+$/|unique:users,login',
            'email'    => 'nullable|email|max:191|unique:users,email',
            'name'     => 'nullable|max:191',
            'role_id'  => 'required|integer|exists:roles',
            'password' => 'nullable|min:' . (int) Config::get('auth.password_min', 6),
            'active'   => 'nullable|boolean',
        ], [
            'login'   => 'Логин',
            'email'   => 'Почта',
            'name'    => 'Имя',
            'role_id' => 'Роль',
        ]);

        // Пароль не задали — придумываем и показываем один раз: в базе он хешем
        $password  = (string) ($data['password'] ?? '');
        $generated = $password === '';

        if ($generated) {
            $password = Password::generate();
        }

        $user = User::register((string) $data['login'], $password, [
            'email'   => (string) ($data['email'] ?? ''),
            'name'    => (string) ($data['name'] ?? $data['login']),
            'role_id' => (int) $data['role_id'],
            'active'  => (int) ($data['active'] ?? 1),
        ]);

        Audit::created('user', $user->id(), 'заведён пользователь ' . (string) $user->login);

        if ($generated) {
            View::stash('password', $password);

            $this->flash('Пользователь заведён. Пароль: ' . $password);
        } else {
            $this->flash('Пользователь заведён');
        }

        return $this->redirect('admin.users.show', ['id' => $user->id()]);
    }

    public function show(int $id): Response
    {
        /** @var User $user */
        $user = $this->require(User::find($id), 'admin.users', 'Пользователь не найден');

        return $this->view('admin/user', [
            'active'     => 'users',
            'user'       => $user,
            'roles'      => Role::all(),
            'devices'    => Devices::of($user->id()),
            'fields'     => UserField::allOrdered(),
            'metaValues' => UserFieldValue::valuesFor($user->id()),
        ], (string) $user->login);
    }

    public function update(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $this->require(User::find($id), 'admin.users', 'Пользователь не найден');

        $data = $this->validate($request, [
            'email'    => 'nullable|email|max:191|unique:users,email,' . $user->id(),
            'name'     => 'nullable|max:191',
            'role_id'  => 'required|integer|exists:roles',
            'active'   => 'nullable|boolean',
            'password' => 'nullable|min:' . (int) Config::get('auth.password_min', 6),
        ], ['email' => 'Почта', 'name' => 'Имя', 'role_id' => 'Роль', 'password' => 'Пароль']);

        $before = [
            'email'   => $user->email,
            'name'    => $user->name,
            'role_id' => $user->raw('role_id'),
            'active'  => $user->raw('active'),
        ];

        $active = (int) ($data['active'] ?? 0);

        // Последнего активного не выключаем: войти станет некому
        if ($active === 0 && $user->isActive() && User::query()->where('active', 1)->count() <= 1) {
            $this->flash('Это последний активный пользователь — отключать его нельзя', 'error');

            return $this->redirect('admin.users.show', ['id' => $user->id()]);
        }

        $user->forceFill([
            'email'   => (string) ($data['email'] ?? ''),
            'name'    => (string) ($data['name'] ?? ''),
            'role_id' => (int) $data['role_id'],
            'active'  => $active,
        ]);

        $password = (string) ($data['password'] ?? '');

        if ($password !== '') {
            // Заодно гасит все сеансы человека — так и задумано
            $user->setPassword($password);
        }

        $user->save();

        // Отключённый не должен доходить до страниц по старой куке
        if ($active === 0) {
            $user->logoutEverywhere();
        }

        Audit::updated('user', $user->id(), 'изменён пользователь ' . (string) $user->login, Audit::between($before, [
            'email'    => $user->email,
            'name'     => $user->name,
            'role_id'  => $user->raw('role_id'),
            'active'   => $user->raw('active'),
            'password' => $password === '' ? '' : 'изменён',
        ]));

        $this->flash('Сохранено');

        return $this->redirect('admin.users.show', ['id' => $user->id()]);
    }

    // $user — тот, кто нажал кнопку: роутер подставляет атрибут по имени аргумента
    public function delete(int $id, User $user): Response
    {
        /** @var User $target */
        $target = $this->require(User::find($id), 'admin.users', 'Пользователь не найден');

        if ($target->id() === $user->id()) {
            $this->flash('Себя удалять нельзя', 'error');

            return $this->redirect('admin.users.show', ['id' => $target->id()]);
        }

        if (User::query()->where('active', 1)->count() <= 1 && $target->isActive()) {
            $this->flash('Это последний активный пользователь — удалять его нельзя', 'error');

            return $this->redirect('admin.users.show', ['id' => $target->id()]);
        }

        $login = (string) $target->login;

        $target->logoutEverywhere();
        $target->delete();

        Audit::deleted('user', $id, 'удалён пользователь ' . $login);

        $this->flash('Пользователь удалён');

        return $this->redirect('admin.users');
    }

    /**
     * Войти под пользователем: смотреть панель и сайт его глазами, с его правами.
     * Право `users.impersonate` уже проверила прослойка маршрута — здесь только
     * то, что от маршрута не зависит: не собой, не отключённым.
     *
     * $user — тот, кто нажал кнопку, его роутер подставляет по имени аргумента
     * из атрибута запроса (см. Authenticate).
     */
    public function impersonate(int $id, User $user): Response
    {
        /** @var User $target */
        $target = $this->require(User::find($id), 'admin.users', 'Пользователь не найден');

        if ($target->id() === $user->id()) {
            $this->flash('Входить под собой незачем', 'error');

            return $this->redirect('admin.users.show', ['id' => $target->id()]);
        }

        if (!$target->isActive()) {
            $this->flash('Пользователь отключён', 'error');

            return $this->redirect('admin.users.show', ['id' => $target->id()]);
        }

        Auth::impersonate($target);

        Audit::action('user', $target->id(), (string) $user->login . ' вошёл под пользователем ' . (string) $target->login);

        $this->flash('Вы вошли как ' . (string) $target->login . ' — вернуться можно кнопкой сверху');

        return $this->redirect('home');
    }

    /**
     * Вернуться в свою сессию. Доступен всегда — маршрут вне group('/users')
     * и без can:, иначе во время подмены выйти назад стало бы нечем.
     */
    public function stopImpersonating(): Response
    {
        $target = Auth::user();
        $admin  = Auth::realUser();

        Auth::stopImpersonating();

        Audit::action('user', $admin?->id() ?? 0, (string) $admin?->login . ' вернулся из-под пользователя ' . (string) $target?->login);

        $this->flash('Вы вернулись в свою учётную запись');

        return $target !== null
            ? $this->redirect('admin.users.show', ['id' => $target->id()])
            : $this->redirect('admin.dashboard');
    }

    /**
     * Массовое действие над отмеченными: включить, выключить, удалить.
     *
     * Себя в список не берём ни при каком действии: выключить или удалить
     * самого себя — верный способ остаться без панели.
     */
    public function bulk(Request $request, User $user): Response
    {
        $data = $this->validate($request, [
            'action' => 'required|in:enable,disable,delete',
        ], ['action' => 'Действие']);

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        $ids = array_values(array_diff($ids, [$user->id()]));

        if ($ids === []) {
            $this->flash('Не отмечено ни одной записи (себя массовые действия не трогают)', 'error');

            return $this->redirect('admin.users');
        }

        $action = (string) $data['action'];
        $done   = 0;

        /** @var User $target */
        foreach (User::query()->whereIn('id', $ids)->get() as $target) {
            // Последнего активного не выключаем и не удаляем: войти станет некому
            if ($action !== 'enable' && $target->isActive() && User::query()->where('active', 1)->count() <= 1) {
                continue;
            }

            match ($action) {
                'enable'  => $target->forceFill(['active' => 1])->save(),
                'disable' => $this->disable($target),
                default   => $this->remove($target),
            };

            $done++;
        }

        $labels = ['enable' => 'включено', 'disable' => 'выключено', 'delete' => 'удалено'];

        Audit::action('user', 0, 'массовое действие: ' . $labels[$action] . ' ' . $done);

        $this->flash(
            $done > 0 ? 'Готово, ' . $labels[$action] . ': ' . $done : 'Ничего не изменилось',
            $done > 0 ? 'ok' : 'error'
        );

        return $this->redirect('admin.users');
    }

    /**
     * Выключить: по старой куке отключённый доходить до страниц не должен.
     */
    private function disable(User $target): void
    {
        $target->forceFill(['active' => 0])->save();
        $target->logoutEverywhere();
    }

    /**
     * Удалить мягко — запись уходит в корзину.
     */
    private function remove(User $target): void
    {
        $target->logoutEverywhere();
        $target->delete();
    }

    /**
     * Роли для списка: id => название.
     *
     * @return array<int, string>
     */
    private function roles(): array
    {
        $roles = [];

        foreach (Role::all() as $role) {
            $roles[$role->id()] = (string) $role->name;
        }

        return $roles;
    }
}
