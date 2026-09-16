<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Devices;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserField;
use Rsgrinko\Proton\Models\UserFieldValue;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;

/**
 * Свой профиль: имя, почта, пароль, свои поля и список устройств.
 *
 * Открыт всем вошедшим — иначе человек без права users.manage не сменил бы
 * себе даже пароль.
 */
final class ProfileController extends Controller
{
    /** Встроенные поля профиля сверх минимума ядра — не путать со своими, из UserField */
    private const COLUMNS = ['name', 'email', 'phone', 'website', 'position', 'location', 'bio'];

    public function show(User $user): Response
    {
        return $this->view('profile', [
            'active'     => '',
            'user'       => $user,
            'devices'    => Devices::of($user->id()),
            'fields'     => UserField::allOrdered(),
            'metaValues' => UserFieldValue::valuesFor($user->id()),
        ], 'Профиль');
    }

    /**
     * Встроенные поля профиля и значения своих полей (UserField) разом —
     * человеку это одна форма, хотя в базе лежит в разных таблицах.
     */
    public function update(Request $request, User $user): Response
    {
        $fields = UserField::allOrdered();

        $rules = [
            'name'     => 'nullable|max:191',
            'email'    => 'nullable|email|max:191|unique:users,email,' . $user->id(),
            'phone'    => 'nullable|max:32',
            'website'  => 'nullable|url|max:191',
            'position' => 'nullable|max:191',
            'location' => 'nullable|max:191',
            'bio'      => 'nullable|max:500',
        ];

        $labels = [
            'name' => 'Имя', 'email' => 'Почта', 'phone' => 'Телефон',
            'website' => 'Сайт', 'position' => 'Должность', 'location' => 'Город', 'bio' => 'О себе',
        ];

        foreach ($fields as $field) {
            $rules[self::metaKey($field)]  = self::metaRule((string) $field->type);
            $labels[self::metaKey($field)] = (string) $field->label;
        }

        $data = $this->validate($request, $rules, $labels);

        $before = array_intersect_key($user->toArray(), array_flip(self::COLUMNS));

        $user->forceFill(array_map(
            static fn (mixed $value): string => (string) ($value ?? ''),
            array_intersect_key($data, array_flip(self::COLUMNS))
        ))->save();

        foreach ($fields as $field) {
            UserFieldValue::put($user->id(), $field->id(), (string) ($data[self::metaKey($field)] ?? ''));
        }

        Audit::updated('user', $user->id(), 'изменён свой профиль', Audit::between(
            $before,
            array_intersect_key($user->toArray(), array_flip(self::COLUMNS))
        ));

        $this->flash('Профиль сохранён');

        return $this->redirect('profile');
    }

    private static function metaKey(UserField $field): string
    {
        return 'meta_' . $field->id();
    }

    private static function metaRule(string $type): string
    {
        return match ($type) {
            UserField::TEXT    => 'nullable|max:2000',
            UserField::NUMBER  => 'nullable|numeric',
            UserField::BOOLEAN => 'nullable|boolean',
            UserField::URL     => 'nullable|url|max:191',
            UserField::DATE    => 'nullable|date',
            default            => 'nullable|max:191',
        };
    }

    /**
     * Смена своего пароля. Старый спрашиваем обязательно: чужой открытый
     * браузер не должен превращаться в захват аккаунта.
     */
    public function password(Request $request, User $user): Response
    {
        $data = $this->validate($request, [
            'current'  => 'required',
            'password' => 'required|min:' . (int) Config::get('auth.password_min', 6) . '|confirmed',
        ], ['current' => 'Текущий пароль', 'password' => 'Новый пароль']);

        if (!$user->verifyPassword((string) $data['current'])) {
            $this->flash('Текущий пароль неверен', 'error');

            return $this->redirect('profile');
        }

        $user->setPassword((string) $data['password']);
        $user->save();

        // Смена пароля гасит все сеансы, включая свой, — этот оставляем в живых
        Auth::keepThisSession();

        Audit::action('user', $user->id(), 'сменил себе пароль');

        $this->flash('Пароль изменён, остальные устройства разлогинены');

        return $this->redirect('profile');
    }

    /**
     * Завершить одно устройство.
     */
    public function revoke(Request $request, User $user): Response
    {
        $id = $request->text('device');

        if (!Devices::revoke($user->id(), $id)) {
            $this->flash('Устройство не найдено', 'error');

            return $this->redirect('profile');
        }

        Audit::action('user', $user->id(), 'завершён сеанс устройства');

        $this->flash('Сеанс завершён');

        return $this->redirect('profile');
    }

    /**
     * Выйти на остальных устройствах.
     */
    public function revokeOthers(User $user): Response
    {
        $user->logoutEverywhere();

        Auth::keepThisSession();

        Audit::action('user', $user->id(), 'завершены сеансы на остальных устройствах');

        $this->flash('На остальных устройствах придётся войти заново');

        return $this->redirect('profile');
    }
}
