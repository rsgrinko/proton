<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\View;

use Rsgrinko\Proton\Access\Policy;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Csrf;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Отрисовка страниц. Шаблоны — обычные PHP-файлы: ни своего языка, ни компиляции,
 * ни кэша шаблонов, который надо чистить после выкладки.
 *
 * Ищутся они сначала в resources/views приложения, потом в framework/View/views —
 * поэтому любой шаблон ядра (каркас, страницы ошибок, пагинация) можно заменить
 * своим, просто положив файл с тем же именем.
 */
final class View
{
    /** @var array<string, mixed> Данные, доступные всем шаблонам */
    private static array $shared = [];

    /**
     * Страница внутри общего каркаса.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], string $title = ''): string
    {
        return self::partial('layouts/main', [
            'content' => self::partial($template, $data),
            'title'   => $title !== '' ? $title : (string) Config::get('app.name', 'Proton'),
            'flash'   => self::takeFlash(),
            'active'  => (string) ($data['active'] ?? ''),
            'viewer'  => self::viewer(),
            'bare'    => false,
        ]);
    }

    /**
     * Страница без шапки и меню — вход, регистрация, первый запуск.
     *
     * @param array<string, mixed> $data
     */
    public static function renderBare(string $template, array $data = [], string $title = ''): string
    {
        return self::partial('layouts/main', [
            'content' => self::partial($template, $data),
            'title'   => $title !== '' ? $title : (string) Config::get('app.name', 'Proton'),
            'flash'   => self::takeFlash(),
            'active'  => '',
            'viewer'  => self::viewer(),
            'bare'    => true,
        ]);
    }

    /**
     * Готовый HTML-ответ — самый частый случай в контроллере.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $template, array $data = [], string $title = '', int $status = 200): Response
    {
        return Response::html(self::render($template, $data, $title), $status);
    }

    /**
     * Кусок разметки без каркаса.
     *
     * @param array<string, mixed> $viewData
     */
    public static function partial(string $viewName, array $viewData = []): string
    {
        // Имена переменных с подчёркиванием: на странице может быть своя
        // переменная $template или $file, и она не должна затирать наши
        $__file = self::locate($viewName);

        extract(array_merge(self::$shared, $viewData), EXTR_OVERWRITE);

        ob_start();

        include $__file;

        return (string) ob_get_clean();
    }

    /**
     * Значение, доступное всем шаблонам, — название приложения, версия, меню.
     */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Экранирование для вывода в HTML. Всё, что пришло от пользователя,
     * печатается только через него.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Адрес по имени маршрута: View::route('admin.users.show', ['id' => 5]).
     * Лишние параметры уходят в query-строку — так удобно тащить фильтры.
     *
     * @param array<string, mixed> $params
     */
    public static function route(string $name, array $params = []): string
    {
        return Router::url($name, array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null));
    }

    /**
     * Скрытое поле с токеном. Обязательно в каждой форме, которая что-то меняет:
     * без него прослойка csrf вернёт 403.
     */
    public static function csrf(): string
    {
        return Csrf::field();
    }

    /**
     * Скрытое поле метода — браузер умеет только GET и POST.
     */
    public static function method(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . self::e(strtoupper($method)) . '">';
    }

    /**
     * Сообщение, которое покажется на следующей странице.
     */
    public static function flash(string $message, string $type = 'ok'): void
    {
        Auth::startSession();

        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
    }

    /**
     * Одноразовое значение для следующей страницы: свежевыпущенный ключ,
     * заполненная форма после ошибки.
     *
     * @param mixed $value
     */
    public static function stash(string $key, mixed $value): void
    {
        Auth::startSession();

        $_SESSION['stash'][$key] = $value;
    }

    /**
     * Забирает отложенное значение — второй раз его уже не будет.
     */
    public static function takeStash(string $key, mixed $default = null): mixed
    {
        Auth::startSession();

        $value = $_SESSION['stash'][$key] ?? $default;

        unset($_SESSION['stash'][$key]);

        return $value;
    }

    /**
     * Прежнее значение поля формы — чтобы после ошибки не заполнять всё заново.
     *
     * @param array<string, mixed> $old
     */
    public static function old(array $old, string $field, mixed $default = ''): mixed
    {
        return $old[$field] ?? $default;
    }

    /**
     * Есть ли у вошедшего право. Пункт меню и кнопку без права не показываем,
     * но полагаться на это нельзя: доступ закрывает прослойка, разметка лишь
     * не дразнит.
     */
    public static function can(string $permission, mixed $subject = null): bool
    {
        // С записью спрашиваем политику: «править свою заметку» — это не право,
        // а правило на конкретную строку
        return $subject === null
            ? self::viewer()->can($permission)
            : Policy::allows($permission, $subject, self::viewer());
    }

    /**
     * @param array<int, string> $permissions
     */
    public static function canAny(array $permissions): bool
    {
        return self::viewer()->canAny($permissions);
    }

    /**
     * Кто смотрит страницу.
     */
    public static function viewer(): Viewer
    {
        return Auth::viewer();
    }

    /**
     * Идёт ли сейчас «вход под пользователем» — каркас страницы по этому
     * показывает верхнюю плашку с кнопкой возврата.
     */
    public static function isImpersonating(): bool
    {
        return Auth::isImpersonating();
    }

    /**
     * Настоящий вошедший во время подмены — тот, кому вернётся сессия.
     */
    public static function impersonator(): ?User
    {
        return Auth::realUser();
    }

    /**
     * Человеческая дата.
     */
    public static function date(?string $value, string $format = 'd.m.Y H:i'): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date($format, $timestamp);
    }

    /**
     * «5 минут назад» — так понятнее, чем голая дата.
     */
    public static function ago(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        $diff   = time() - $timestamp;
        $suffix = $diff < 0 ? ' вперёд' : ' назад';
        $diff   = abs($diff);

        return match (true) {
            $diff < 60    => $diff . ' с' . $suffix,
            $diff < 3600  => (int) ($diff / 60) . ' мин' . $suffix,
            $diff < 86400 => (int) ($diff / 3600) . ' ч' . $suffix,
            default       => (int) ($diff / 86400) . ' дн' . $suffix,
        };
    }

    /**
     * Браузер и система из строки User-Agent — для списка устройств. Разбор
     * нарочно грубый: нужно отличить свой телефон от чужого компьютера.
     */
    public static function device(?string $agent): string
    {
        $agent = trim((string) $agent);

        if ($agent === '') {
            return 'браузер неизвестен';
        }

        // Порядок важен: все они представляются ещё и Chrome
        $browsers = [
            'YaBrowser' => 'Яндекс.Браузер',
            'Edg'       => 'Edge',
            'OPR'       => 'Opera',
            'Firefox'   => 'Firefox',
            'Chrome'    => 'Chrome',
            'Safari'    => 'Safari',
        ];

        $browser = 'браузер неизвестен';

        foreach ($browsers as $needle => $label) {
            if (str_contains($agent, $needle)) {
                $browser = $label;

                break;
            }
        }

        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Linux' => 'Linux'] as $needle => $system) {
            if (str_contains($agent, $needle)) {
                return $browser . ', ' . $system;
            }
        }

        return $browser;
    }

    /**
     * Фото профиля или заглушка того же размера — кружок с первой буквой имени
     * на ровном фоне, свой для каждого пользователя. Без файла на диске
     * заглушка рисуется чистым CSS, поэтому подмена никогда не сдвигает
     * соседей: и то и другое — квадрат `--size` с `border-radius: 50%`.
     */
    public static function avatar(User $user, bool $large = false): string
    {
        $class = 'avatar' . ($large ? ' lg' : '');
        $name  = trim((string) ($user->name ?: $user->login));

        if ($user->hasAvatar()) {
            // Хвост в адресе — от пути файла: заменили фото — изменился путь,
            // изменилась и ссылка, старая копия в кэше браузера не мешает
            $version = substr(md5((string) $user->raw('avatar_path')), 0, 8);
            $url     = self::route('avatar.show', ['id' => $user->id()]) . '?v=' . $version;

            return '<img class="' . $class . '" src="' . self::e($url) . '" alt="' . self::e($name) . '">';
        }

        $letter = $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        $hue    = ($user->id() * 47) % 360;

        return '<span class="' . $class . ' avatar-placeholder" style="--avatar-hue: ' . $hue . '" title="' . self::e($name) . '">'
            . self::e($letter) . '</span>';
    }

    /**
     * Кнопка «скопировать» рядом со значением: ключ, идентификатор, команда.
     * Обязательно type="button" — кнопки стоят и внутри форм.
     */
    public static function copy(string $value, string $title = 'скопировать'): string
    {
        return '<button type="button" class="copy" data-copy="' . self::e($value) . '">' . self::e($title) . '</button>';
    }

    /**
     * Забирает накопленные сообщения и очищает их.
     *
     * @return array<int, array{message: string, type: string}>
     */
    private static function takeFlash(): array
    {
        Auth::startSession();

        $flash = $_SESSION['flash'] ?? [];

        $_SESSION['flash'] = [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * Ищет файл шаблона: сначала в приложении, потом в ядре.
     */
    private static function locate(string $viewName): string
    {
        $name = trim(str_replace('.', '/', $viewName), '/');

        foreach ([APP_ROOT . '/resources/views/', APP_ROOT . '/framework/View/views/'] as $dir) {
            $file = $dir . $name . '.php';

            if (is_file($file)) {
                return $file;
            }
        }

        throw new ProtonException('Шаблон не найден: ' . $viewName);
    }
}
