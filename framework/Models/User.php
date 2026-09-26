<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;
use Rsgrinko\Proton\Database\Model\Relations\HasMany;
use Rsgrinko\Proton\Files\Attachment;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Support\Str;

/**
 * Пользователь: вход по логину, права — из выданной роли.
 *
 * Пароль хранится только хешем, в toArray() и в JSON он не попадает (см. $hidden):
 * модель уезжает и в шаблоны, и в ответы API, и забыть про это нельзя.
 */
final class User extends Model
{
    protected static string $table = 'users';

    protected array $fillable = [
        'login', 'email', 'name', 'role_id', 'active',
        // Профиль сверх минимума ядра: не используются нигде, кроме самой
        // карточки — свободны для своих нужд в приложении поверх фреймворка
        'phone', 'website', 'position', 'location', 'bio',
        'theme',
    ];

    protected array $hidden = ['password_hash'];

    protected array $casts = [
        'active'  => 'bool',
        'role_id' => 'int',
    ];

    /** Мягкое удаление: человека возвращают из корзины, пока его не добили */
    protected bool $softDelete = true;

    /**
     * Права текущего запроса — посчитаны один раз. Без этого кэша `Auth::viewer()`
     * заново лез бы в `roles` на каждую проверку `View::can()`, а в шапке
     * панели их за один показ страницы бывает больше десятка (пункт меню —
     * это одна проверка), и на удалённой базе счёт идёт на секунды.
     *
     * @var array<int, string>|null
     */
    private ?array $permissionsCache = null;

    private ?bool $superuserCache = null;

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(RememberToken::class, 'user_id');
    }

    /**
     * Права пользователя — из его роли. Роли нет — прав нет.
     *
     * @return array<int, string>
     */
    /**
     * Со встроенной ролью администратора: у него всё, включая права, которых
     * ещё нет в реестре на момент, когда его права посчитали.
     */
    public function isSuperuser(): bool
    {
        if ($this->superuserCache !== null) {
            return $this->superuserCache;
        }

        $role = Role::find((int) $this->raw('role_id'));

        return $this->superuserCache = $role !== null && $role->isSystem();
    }

    public function permissions(): array
    {
        if ($this->permissionsCache !== null) {
            return $this->permissionsCache;
        }

        $role = Role::find((int) $this->raw('role_id'));

        return $this->permissionsCache = $role === null ? [] : $role->permissions();
    }

    public function isActive(): bool
    {
        return (int) $this->raw('active') === 1;
    }

    /**
     * 'light' или 'dark' — ровно два значения, никакого «как в системе»:
     * оформление панели не должно расходиться с тем, что согласовали.
     */
    public function theme(): string
    {
        return (string) $this->raw('theme') === 'dark' ? 'dark' : 'light';
    }

    /**
     * Текущее фото профиля — вложение с entity `avatar`. Их может накопиться
     * несколько только между загрузкой новой и удалением старой (см.
     * ProfileController::avatarUpload), поэтому действующее — всегда свежее.
     */
    public function avatarAttachment(): ?Attachment
    {
        return Attachment::of('avatar', $this->id())[0] ?? null;
    }

    /**
     * Не просто «вложение есть», а «файл правда на диске» — иначе битая
     * ссылка (файл потёрли в обход загрузки) рисуется у всех сломанной
     * картинкой вместо заглушки.
     */
    public function hasAvatar(): bool
    {
        $attachment = $this->avatarAttachment();

        return $attachment !== null && Storage::exists((string) $attachment->path);
    }

    public function emailVerified(): bool
    {
        return trim((string) $this->raw('email_verified_at')) !== '';
    }

    /**
     * Ставит новый пароль. Хеш считается здесь, чтобы «сохранить пароль как есть»
     * нельзя было сделать по невнимательности.
     */
    public function setPassword(string $password): void
    {
        $this->setAttribute('password_hash', Password::hash($password));

        // Смена пароля гасит все прежние сеансы: пароль меняют, когда его увели
        $this->setAttribute('sessions_from', Connection::now());
    }

    public function verifyPassword(string $password): bool
    {
        return Password::verify($password, (string) $this->raw('password_hash'));
    }

    /**
     * Отмечает вход: время и адрес пригождаются и человеку, и разбору инцидента.
     */
    public function markLogin(string $ip): void
    {
        $this->forceFill(['last_login_at' => Connection::now(), 'last_login_ip' => $ip])->save();
    }

    /**
     * Завершает все сеансы пользователя: и сессии, и долгие куки.
     */
    public function logoutEverywhere(): void
    {
        $this->forceFill(['sessions_from' => Connection::now()])->save();

        RememberToken::query()->where('user_id', $this->id())->forceDelete();
        UserSession::query()->where('user_id', $this->id())->update(['revoked_at' => Connection::now()]);
    }

    /**
     * Пользователь по логину или адресу почты — вход разрешён и так, и так.
     */
    public static function findByCredential(string $credential): ?self
    {
        $credential = trim($credential);

        if ($credential === '') {
            return null;
        }

        /** @var self|null $user */
        $user = self::query()
            ->where(static function ($query) use ($credential): void {
                $query->where('login', $credential)->orWhere('email', $credential);
            })
            ->first();

        return $user;
    }

    /**
     * Заводит пользователя: пароль сразу хешем, логин без пробелов.
     *
     * @param array<string, mixed> $attributes
     */
    public static function register(string $login, string $password, array $attributes = []): self
    {
        $user = new self();

        $user->forceFill(array_merge([
            'login'  => trim($login),
            'email'  => trim((string) ($attributes['email'] ?? '')),
            'name'   => trim((string) ($attributes['name'] ?? $login)),
            'active' => 1,
        ], $attributes));

        $user->setPassword($password);
        $user->save();

        return $user;
    }

    /**
     * Токен подтверждения почты или сброса пароля: в базе только хеш.
     */
    public static function newToken(): string
    {
        return Str::random(48);
    }
}
