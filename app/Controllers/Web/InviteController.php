<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Invitation;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;

/**
 * Переход по ссылке из приглашения: человек придумывает логин и пароль,
 * почта и роль уже заданы.
 *
 * AUTH_REGISTRATION здесь не смотрится нарочно: приглашения и нужны, чтобы
 * закрыть открытую регистрацию и пускать только позванных.
 */
final class InviteController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->invitation($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        return $this->bare('auth/invite', [
            'token' => $token,
            'email' => (string) $invitation->email,
        ], 'Приглашение');
    }

    public function store(Request $request, string $token): Response
    {
        $invitation = $this->invitation($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        $data = $this->validate($request, [
            'login'    => 'required|min:3|max:100|regex:/^[A-Za-z0-9._-]+$/|unique:users,login',
            'name'     => 'nullable|max:191',
            'password' => 'required|min:' . (int) Config::get('auth.password_min', 6) . '|confirmed',
        ], ['login' => 'Логин', 'name' => 'Имя', 'password' => 'Пароль']);

        $email = (string) $invitation->email;

        // Пока письмо шло, адрес могли занять — регистрацией или руками из панели
        if (User::query()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->count() > 0) {
            $invitation->revoke();

            $this->flash('Учётная запись с адресом ' . $email . ' уже есть — войдите в неё', 'error');

            return $this->redirect('login');
        }

        $user = User::register((string) $data['login'], (string) $data['password'], [
            'email'   => $email,
            'name'    => (string) ($data['name'] ?? $data['login']),
            'role_id' => (int) $invitation->raw('role_id'),
            // Ссылку получили на этот адрес — значит, он живой и свой
            'email_verified_at' => Connection::now(),
        ]);

        $invitation->accept($user);

        Audit::created('user', $user->id(), 'регистрация по приглашению #' . $invitation->id() . ': ' . (string) $user->login);
        Events::fire('user.registered', ['user' => $user, 'invitation' => $invitation]);

        Auth::login($user, false);

        $this->flash('Добро пожаловать!');

        return $this->redirect('home');
    }

    /**
     * Годное приглашение с живой ролью. Роль могли удалить, пока письмо
     * лежало во входящих, — заводить человека без роли незачем.
     */
    private function invitation(string $token): ?Invitation
    {
        $invitation = Invitation::match($token);

        return $invitation !== null && $invitation->role() !== null ? $invitation : null;
    }

    private function invalid(): Response
    {
        $this->flash('Приглашение недействительно или устарело. Попросите прислать новое', 'error');

        return $this->redirect('login');
    }
}
