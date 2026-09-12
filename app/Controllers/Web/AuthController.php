<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\View\View;

/**
 * Вход, выход и страница первого запуска.
 */
final class AuthController extends Controller
{
    /**
     * Форма входа.
     */
    public function showLogin(): Response
    {
        return $this->bare('auth/login', [
            'remember' => Auth::rememberDays() > 0,
        ], 'Вход');
    }

    /**
     * Проверка логина и пароля.
     */
    public function login(Request $request): Response
    {
        $result = Auth::attempt(
            $request->text('login'),
            (string) $request->input('password', ''),
            $request->ip()
        );

        if ($result['user'] === null) {
            Audit::login($request->text('login'), false);

            $this->flash($result['error'], 'error');

            return $this->redirect('login');
        }

        Auth::login($result['user'], $request->boolean('remember'));

        Audit::login((string) $result['user']->login);
        Events::fire('user.login', ['user' => $result['user']]);

        // Человек шёл по ссылке, а его отправили на вход — вернём куда собирался
        $intended = (string) View::takeStash('intended', '');

        return $intended !== '' && str_starts_with($intended, '/')
            ? Response::redirect($intended)
            : $this->redirect('home');
    }

    public function logout(Request $request): Response
    {
        $login = Auth::viewer()->login();

        Audit::logout($login);
        Events::fire('user.logout', ['login' => $login]);

        Auth::logout();

        $this->flash('Вы вышли');

        return $this->redirect('login');
    }
}
