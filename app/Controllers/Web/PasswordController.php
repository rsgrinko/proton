<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Models\AuthToken;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\RateLimit\RateLimiter;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

/**
 * Забытый пароль: письмо со ссылкой и установка нового.
 *
 * Ответ на запрос всегда одинаковый — «письмо отправлено», даже если такого
 * адреса нет. Иначе форма превращается в проверку, зарегистрирован ли человек.
 */
final class PasswordController extends Controller
{
    public function showForgot(): Response
    {
        return $this->bare('auth/forgot', [], 'Восстановление пароля');
    }

    public function forgot(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:191'], ['email' => 'Почта']);

        // Форму сброса легко превратить в рассылку по чужим адресам —
        // ограничиваем частоту по адресу отправителя запроса
        $limiter = new RateLimiter();

        if (!$limiter->attempt('forgot:' . $request->ip(), 5, 900)) {
            $this->flash('Слишком много запросов. Попробуйте через четверть часа', 'error');

            return $this->redirect('password.forgot');
        }

        $user = User::findBy('email', (string) $data['email']);

        if ($user !== null && $user->isActive()) {
            $token = AuthToken::issue($user->id(), AuthToken::RESET, $request->ip());
            $link  = rtrim((string) Config::get('app.url', ''), '/') . View::route('password.reset', ['token' => $token]);

            Message::to((string) $user->email)
                ->subject('Восстановление пароля — ' . (string) Config::get('app.name', 'Proton'))
                ->view('mail/reset', ['user' => $user, 'link' => $link])
                ->queue();

            Audit::action('user', $user->id(), 'запрошено восстановление пароля');
        }

        $this->flash('Если такой адрес зарегистрирован, письмо со ссылкой уже отправлено');

        return $this->redirect('login');
    }

    public function showReset(Request $request, string $token): Response
    {
        if (AuthToken::match($token, AuthToken::RESET) === null) {
            $this->flash('Ссылка недействительна или устарела', 'error');

            return $this->redirect('password.forgot');
        }

        return $this->bare('auth/reset', ['token' => $token], 'Новый пароль');
    }

    public function reset(Request $request, string $token): Response
    {
        $found = AuthToken::match($token, AuthToken::RESET);

        if ($found === null) {
            $this->flash('Ссылка недействительна или устарела', 'error');

            return $this->redirect('password.forgot');
        }

        $data = $this->validate($request, [
            'password' => 'required|min:' . (int) Config::get('auth.password_min', 6) . '|confirmed',
        ], ['password' => 'Пароль']);

        $user = $found->user();

        if ($user === null) {
            $this->flash('Пользователь не найден', 'error');

            return $this->redirect('login');
        }

        $found->markUsed();

        // setPassword заодно гасит все сеансы: если пароль восстанавливают,
        // старым входам доверять нельзя
        $user->setPassword((string) $data['password']);
        $user->save();

        Audit::action('user', $user->id(), 'пароль восстановлен по ссылке из письма');

        $this->flash('Пароль изменён, войдите с новым');

        return $this->redirect('login');
    }
}
