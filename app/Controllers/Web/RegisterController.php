<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Models\AuthToken;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\View\View;

/**
 * Регистрация и подтверждение почты.
 *
 * Регистрацию можно закрыть настройкой AUTH_REGISTRATION — тогда пользователей
 * заводит только администратор. Подтверждение почты включается отдельно
 * (AUTH_VERIFY_EMAIL): без него человек пользуется сервисом сразу.
 */
final class RegisterController extends Controller
{
    public function show(): Response
    {
        if (!$this->allowed()) {
            $this->flash('Регистрация закрыта', 'error');

            return $this->redirect('login');
        }

        return $this->bare('auth/register', [], 'Регистрация');
    }

    public function store(Request $request): Response
    {
        if (!$this->allowed()) {
            $this->flash('Регистрация закрыта', 'error');

            return $this->redirect('login');
        }

        $data = $this->validate($request, [
            'login'    => 'required|min:3|max:100|regex:/^[A-Za-z0-9._-]+$/|unique:users,login',
            'email'    => 'required|email|max:191|unique:users,email',
            'name'     => 'nullable|max:191',
            'password' => 'required|min:' . (int) Config::get('auth.password_min', 6) . '|confirmed',
        ], [
            'login'    => 'Логин',
            'email'    => 'Почта',
            'name'     => 'Имя',
            'password' => 'Пароль',
        ]);

        // Роль задана настройкой и проверена на отсутствие прав управления
        // (Role::forRegistration()); allowed() выше уже убедился, что она есть
        /** @var Role $role */
        $role = Role::forRegistration();

        $user = User::register((string) $data['login'], (string) $data['password'], [
            'email'   => (string) $data['email'],
            'name'    => (string) ($data['name'] ?? $data['login']),
            'role_id' => $role->id(),
        ]);

        Audit::created('user', $user->id(), 'регистрация: ' . $user->login);
        Events::fire('user.registered', ['user' => $user]);

        if ($this->verifyRequired()) {
            $this->sendVerification($user, $request->ip());

            $this->flash('Мы отправили письмо на ' . (string) $user->email . '. Перейдите по ссылке из него, чтобы подтвердить адрес');

            return $this->redirect('login');
        }

        Auth::login($user, false);

        $this->flash('Добро пожаловать!');

        return $this->redirect('home');
    }

    /**
     * Переход по ссылке из письма.
     */
    public function verify(Request $request, string $token): Response
    {
        $found = AuthToken::match($token, AuthToken::VERIFY);

        if ($found === null) {
            $this->flash('Ссылка недействительна или устарела. Попросите новую', 'error');

            return $this->redirect('login');
        }

        $user = $found->user();

        if ($user === null) {
            $this->flash('Пользователь не найден', 'error');

            return $this->redirect('login');
        }

        $found->markUsed();

        $user->forceFill(['email_verified_at' => Connection::now()])->save();

        Audit::action('user', $user->id(), 'подтверждена почта ' . (string) $user->email);

        Auth::login($user, false);

        $this->flash('Почта подтверждена');

        return $this->redirect('home');
    }

    /**
     * Отправляет письмо со ссылкой подтверждения.
     */
    private function sendVerification(User $user, string $ip): void
    {
        $token = AuthToken::issue($user->id(), AuthToken::VERIFY, $ip);

        $link = rtrim((string) Config::get('app.url', ''), '/') . View::route('verify', ['token' => $token]);

        // Через очередь: страница регистрации не должна ждать чужой SMTP
        Message::to((string) $user->email)
            ->subject('Подтверждение адреса — ' . (string) Config::get('app.name', 'Proton'))
            ->view('mail/verify', ['user' => $user, 'link' => $link])
            ->queue();
    }

    /**
     * Открыта ли регистрация. Нет годной роли для новичков — закрыта, даже если
     * AUTH_REGISTRATION включён: выдать без роли или с правами управления
     * хуже, чем не пустить. Причина — в логе и в состоянии сервиса.
     */
    private function allowed(): bool
    {
        if (!(bool) Config::get('auth.registration', true)) {
            return false;
        }

        $problem = Role::registrationProblem();

        if ($problem !== '') {
            (new Logger('app'))->error('Регистрация закрыта: ' . $problem);

            return false;
        }

        return true;
    }

    private function verifyRequired(): bool
    {
        return (bool) Config::get('auth.verify_email', false);
    }
}
