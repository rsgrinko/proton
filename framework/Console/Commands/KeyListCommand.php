<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;

/**
 * список ключей API.
 */
final class KeyListCommand extends Command
{
    public function name(): string
    {
        return 'key:list';
    }

    public function description(): string
    {
        return 'список ключей доступа к API';
    }

    public function run(): int
    {
        /** @var array<int, ApiToken> $tokens */
        $tokens = ApiToken::query()->orderBy('id')->get();

        if ($tokens === []) {
            $this->line('Ключей нет');

            return 0;
        }

        $owners = [];

        foreach (User::all() as $user) {
            $owners[$user->id()] = (string) $user->login;
        }

        $rows = [];

        foreach ($tokens as $token) {
            $rows[] = [
                (string) $token->id(),
                (string) $token->name,
                $token->mask(),
                $owners[(int) $token->raw('user_id')] ?? '—',
                (int) $token->raw('active') === 1 ? 'да' : 'нет',
                (string) ($token->raw('last_used_at') ?? '—'),
            ];
        }

        $this->table(['id', 'Название', 'Ключ', 'Владелец', 'Активен', 'Последнее использование'], $rows);

        return 0;
    }
}
