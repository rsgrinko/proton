<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Str;

/**
 * Приглашение: адрес, роль и одноразовая ссылка.
 *
 *     $sent = Invitation::issue('anna@example.com', $role->id(), $admin->id());
 *     $link = Router::absolute('invite', ['token' => $sent['token']]);
 *
 *     $invitation = Invitation::match($token);   // null — ссылка негодная
 *     $invitation->accept($user);
 *
 * Роль выбирают при отправке, а не при входе: человек по ссылке роль не
 * выбирает и не видит. Проверку «роль не шире прав приглашающего» делает
 * контроллер — модель про того, кто смотрит, не знает.
 *
 * Состояние не хранится колонкой, а считается из дат (state()): иначе
 * просроченное приглашение оставалось бы «ждёт» до первого, кто его
 * пересчитает.
 */
final class Invitation extends Model
{
    protected static string $table = 'invitations';

    protected array $casts = [
        'role_id'    => 'int',
        'invited_by' => 'int',
        'user_id'    => 'int',
    ];

    public const PENDING  = 'pending';
    public const ACCEPTED = 'accepted';
    public const EXPIRED  = 'expired';
    public const REVOKED  = 'revoked';

    public const STATES = [
        self::PENDING  => 'ждёт',
        self::ACCEPTED => 'принято',
        self::EXPIRED  => 'истекло',
        self::REVOKED  => 'отозвано',
    ];

    /**
     * Завести приглашение. Открытый токен возвращается один раз — он уходит
     * в письмо, в базе остаётся хеш.
     *
     * Прежние ждущие приглашения на тот же адрес гасятся: иначе после
     * повторной отправки с другой ролью по старой ссылке всё ещё заводилась
     * бы учётная запись со старой ролью.
     *
     * @return array{invitation: self, token: string}
     */
    public static function issue(string $email, int $roleId, int $invitedBy = 0): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');

        self::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Connection::now()]);

        $token = Str::random(48);

        $invitation = new self();

        $invitation->forceFill([
            'email'      => $email,
            'role_id'    => $roleId,
            'token_hash' => self::hash($token),
            'invited_by' => $invitedBy > 0 ? $invitedBy : null,
            'expires_at' => Connection::at(self::lifetime()),
        ])->save();

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Ждущее приглашение по токену из ссылки. Принятое, отозванное и
     * просроченное — null: второй раз по той же ссылке не заводятся.
     */
    public static function match(string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        /** @var self|null $found */
        $found = self::query()->where('token_hash', self::hash($token))->first();

        return $found !== null && $found->state() === self::PENDING ? $found : null;
    }

    /**
     * Сколько живёт ссылка, секунды. Настройка в днях: приглашение читают не
     * сразу, суток (как у сброса пароля) обычно мало.
     */
    public static function lifetime(): int
    {
        return max(1, (int) Config::get('auth.invite_days', 7)) * 86400;
    }

    public function state(): string
    {
        if (trim((string) $this->raw('accepted_at')) !== '') {
            return self::ACCEPTED;
        }

        if (trim((string) $this->raw('revoked_at')) !== '') {
            return self::REVOKED;
        }

        if (strtotime((string) $this->raw('expires_at')) < time()) {
            return self::EXPIRED;
        }

        return self::PENDING;
    }

    public function isPending(): bool
    {
        return $this->state() === self::PENDING;
    }

    public function role(): ?Role
    {
        return Role::find((int) $this->raw('role_id'));
    }

    /**
     * Ссылкой воспользовались: запоминаем, кто завёлся.
     */
    public function accept(User $user): void
    {
        $this->forceFill([
            'accepted_at' => Connection::now(),
            'user_id'     => $user->id(),
        ])->save();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => Connection::now()])->save();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
