<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Auth\Devices;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

/**
 * Пользователи: список, карточка, заведение, правка, отключение и удаление.
 */
final class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->text('q');

        $query = User::query()
            ->when($search, static function ($query, string $needle): void {
                $query->where(static function ($nested) use ($needle): void {
                    $nested->whereLike('login', $needle)->orWhere('email', 'LIKE', '%' . $needle . '%');
                });
            })
            ->orderBy('id');

        return $this->view('admin/users', [
            'active' => 'users',
            'page'   => $query->paginate($this->page($request), $this->perPage()),
            'roles'  => $this->roles(),
            'search' => $search,
        ], 'Пользователи');
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
            'active'  => 'users',
            'user'    => $user,
            'roles'   => Role::all(),
            'devices' => Devices::of($user->id()),
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

    public function delete(int $id, User $current): Response
    {
        /** @var User $user */
        $user = $this->require(User::find($id), 'admin.users', 'Пользователь не найден');

        if ($user->id() === $current->id()) {
            $this->flash('Себя удалять нельзя', 'error');

            return $this->redirect('admin.users.show', ['id' => $user->id()]);
        }

        if (User::query()->where('active', 1)->count() <= 1 && $user->isActive()) {
            $this->flash('Это последний активный пользователь — удалять его нельзя', 'error');

            return $this->redirect('admin.users.show', ['id' => $user->id()]);
        }

        $login = (string) $user->login;

        $user->logoutEverywhere();
        $user->delete();

        Audit::deleted('user', $id, 'удалён пользователь ' . $login);

        $this->flash('Пользователь удалён');

        return $this->redirect('admin.users');
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
