<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Settings;
use Throwable;

/**
 * Консольная утилита: `php bin/proton <команда> [аргументы]`.
 *
 * Здесь только разбор аргументов, реестр команд и справка. Команды ядра
 * перечислены в COMMANDS, свои приложение дописывает в config/commands.php —
 * файл возвращает список классов.
 */
final class Application
{
    /**
     * Команды ядра по разделам — в этом же порядке печатается справка.
     *
     * @var array<string, array<int, class-string<Command>>>
     */
    private const COMMANDS = [
        'Установка и обслуживание' => [
            Commands\InstallCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\SeedCommand::class,
            Commands\AppKeyCommand::class,
            Commands\StatusCommand::class,
            Commands\RouteListCommand::class,
            Commands\CacheClearCommand::class,
            Commands\LogsPurgeCommand::class,
        ],
        'Разработка' => [
            Commands\ServeCommand::class,
            Commands\TestCommand::class,
            Commands\MakeCrudCommand::class,
            Commands\MakeModelCommand::class,
            Commands\MakeControllerCommand::class,
            Commands\MakeMigrationCommand::class,
            Commands\MakeJobCommand::class,
            Commands\MakeCommandCommand::class,
            Commands\MakeTestCommand::class,
        ],
        'Очередь и расписание' => [
            Commands\WorkerCommand::class,
            Commands\WorkerRestartCommand::class,
            Commands\ScheduleRunCommand::class,
            Commands\QueueStatusCommand::class,
            Commands\QueueRetryCommand::class,
            Commands\QueuePurgeCommand::class,
        ],
        'Пользователи и доступ' => [
            Commands\UserCreateCommand::class,
            Commands\UserListCommand::class,
            Commands\UserPasswordCommand::class,
            Commands\UserDeleteCommand::class,
            Commands\RoleListCommand::class,
            Commands\KeyCreateCommand::class,
            Commands\KeyListCommand::class,
            Commands\KeyRevokeCommand::class,
        ],
        'Почта' => [
            Commands\MailTestCommand::class,
        ],
        'Резервные копии' => [
            Commands\BackupCreateCommand::class,
            Commands\BackupListCommand::class,
            Commands\BackupRestoreCommand::class,
        ],
        'Вебхуки' => [
            Commands\WebhookListCommand::class,
            Commands\WebhookTestCommand::class,
        ],
    ];

    /** @var array<int, string> Позиционные аргументы */
    private array $args = [];

    /** @var array<string, string> Опции вида --name=value */
    private array $options = [];

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';

        $this->parseArguments(array_slice($argv, 2));

        if (in_array($name, ['help', '--help', '-h', 'list'], true)) {
            return $this->help();
        }

        $command = $this->find($name);

        if ($command === null) {
            return $this->unknown($name);
        }

        // Команда должна работать на тех же настройках, что и веб-часть:
        // значения из панели ложатся поверх .env
        Settings::apply();

        try {
            return $command->withInput($this->args, $this->options)->run();
        } catch (Throwable $e) {
            $this->line('ОШИБКА: ' . $e->getMessage());

            if ((bool) Config::get('app.debug', false)) {
                $this->line($e->getFile() . ':' . $e->getLine());
                $this->line($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Все команды — нужно справке и тестам.
     *
     * @return array<int, Command>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::groups() as $group) {
            foreach ($group as $class) {
                $commands[] = new $class();
            }
        }

        return $commands;
    }

    /**
     * Команды ядра плюс команды приложения.
     *
     * @return array<string, array<int, class-string<Command>>>
     */
    public static function groups(): array
    {
        $groups = self::COMMANDS;

        $file = APP_ROOT . '/config/commands.php';

        if (is_file($file)) {
            /** @var array<int, class-string<Command>> $own */
            $own = (array) require $file;

            if ($own !== []) {
                $groups['Команды приложения'] = $own;
            }
        }

        return $groups;
    }

    private function find(string $name): ?Command
    {
        foreach (self::commands() as $command) {
            if ($command->name() === $name) {
                return $command;
            }
        }

        return null;
    }

    private function help(): int
    {
        $this->line((string) Config::get('app.name', 'Proton') . ' — консольная утилита');
        $this->line('');
        $this->line('Использование: php bin/proton <команда> [аргументы]');

        foreach (self::groups() as $title => $classes) {
            $this->line('');
            $this->line($title . ':');

            foreach ($classes as $class) {
                /** @var Command $command */
                $command = new $class();

                $usage = $command->usage();

                // Длинную строку вызова не растягиваем колонкой
                if (mb_strlen($usage) > 40) {
                    $this->line('  ' . $usage);
                    $this->line('      ' . $command->description());

                    continue;
                }

                $this->line('  ' . $this->pad($usage, 42) . $command->description());
            }
        }

        $this->line('');
        $this->line('Настройки берутся из .env, см. .env.example.');

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->line('Неизвестная команда: ' . $command);
        $this->line('Список команд: php bin/proton help');

        return 1;
    }

    /**
     * @param array<int, string> $arguments
     */
    private function parseArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                $argument = substr($argument, 2);

                if (str_contains($argument, '=')) {
                    [$name, $value] = explode('=', $argument, 2);

                    $this->options[$name] = $value;
                } else {
                    $this->options[$argument] = '1';
                }

                continue;
            }

            $this->args[] = $argument;
        }
    }

    private function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text . ' ' : $text . str_repeat(' ', $width - $length);
    }

    private function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }
}
