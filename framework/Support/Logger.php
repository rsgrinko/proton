<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Логи в var/log/app-ГГГГ-ММ-ДД.log.
 *
 * Формат строки: [дата] УРОВЕНЬ [канал] сообщение {контекст}
 * Переносы внутри сообщения схлопываются в «|»: одна запись — одна строка,
 * на этом держится разбор файла на странице логов.
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Уровни по возрастанию важности — чтобы отсекать лишнее */
    private const WEIGHTS = [
        self::DEBUG   => 10,
        self::INFO    => 20,
        self::WARNING => 30,
        self::ERROR   => 40,
    ];

    /** Начало имени файла лога */
    public const PREFIX = 'app-';

    private string $channel;
    private string $dir;
    private string $minLevel;

    public function __construct(string $channel = 'app', ?string $dir = null, ?string $minLevel = null)
    {
        $this->channel  = $channel;
        $this->dir      = $dir ?? (string) Config::get('paths.log', APP_ROOT . '/var/log');
        $this->minLevel = $minLevel ?? (string) Config::get('log.level', self::INFO);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::WEIGHTS[$level] ?? 0) < (self::WEIGHTS[$this->minLevel] ?? 20)) {
            return;
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        // Идентификатор цепочки подставляем сами: помнить про него в каждом
        // вызове невозможно, а нужен он именно тогда, когда что-то пошло не так
        if (RequestId::has() && !isset($context['request_id'])) {
            $context = array_merge(['request_id' => RequestId::current()], $context);
        }

        $line = sprintf(
            "[%s] %s [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $this->channel,
            $this->oneLine($message),
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents($this->file(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Схлопывает переносы строк: одна запись — одна строка, иначе разбор файла
     * на странице логов рассыпается.
     */
    private function oneLine(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\n"], ' | ', $text));
    }

    /**
     * Файл сегодняшнего лога.
     */
    public function file(): string
    {
        return $this->dir . '/' . self::PREFIX . date('Y-m-d') . '.log';
    }

    /**
     * Список файлов логов, свежие сверху.
     *
     * @return array<int, array{name: string, path: string, size: int, mtime: int}>
     */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $result = [];

        foreach ((array) glob($this->dir . '/' . self::PREFIX . '*.log') as $path) {
            if (!is_string($path)) {
                continue;
            }

            $result[] = [
                'name'  => basename($path),
                'path'  => $path,
                'size'  => (int) filesize($path),
                'mtime' => (int) filemtime($path),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $result;
    }

    /**
     * Удаляет логи старше указанного числа дней, возвращает имена удалённых.
     * Ноль дней означает «не чистить», сегодняшний файл не трогаем никогда.
     *
     * @return array<int, string>
     */
    public function purge(?int $days = null): array
    {
        $days = $days ?? (int) Config::get('log.keep_days', 30);

        if ($days <= 0) {
            return [];
        }

        $edge    = strtotime('-' . $days . ' days');
        $today   = $this->file();
        $removed = [];

        foreach ($this->files() as $file) {
            if ($file['mtime'] >= $edge || $file['path'] === $today) {
                continue;
            }

            if (@unlink($file['path'])) {
                $removed[] = $file['name'];
            }
        }

        return $removed;
    }

    /**
     * Последние строки файла — читаем с конца, чтобы не поднимать в память
     * лог на десятки мегабайт.
     *
     * @return array<int, string>
     */
    public function tail(string $fileName, int $lines = 200): array
    {
        $path = $this->dir . '/' . basename($fileName);

        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $chunk    = 8192;
        $position = filesize($path) ?: 0;
        $buffer   = '';

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read     = (int) min($chunk, $position);
            $position -= $read;

            fseek($handle, $position);

            $buffer = (string) fread($handle, $read) . $buffer;
        }

        fclose($handle);

        $all = array_values(array_filter(explode("\n", trim($buffer)), static fn (string $line): bool => $line !== ''));

        return array_slice($all, -$lines);
    }

    /**
     * Разбирает строку лога на части — для таблицы на странице логов.
     *
     * @return array{time: string, level: string, channel: string, message: string, context: string}
     */
    public static function parse(string $line): array
    {
        $pattern = '/^\[(?<time>[^\]]+)\]\s+(?<level>[A-Z]+)\s+\[(?<channel>[^\]]*)\]\s+(?<message>.*)$/u';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return ['time' => '', 'level' => '', 'channel' => '', 'message' => $line, 'context' => ''];
        }

        $message = (string) $matches['message'];
        $context = '';

        // Контекст — последний JSON-объект в строке
        $brace = strpos($message, ' {');

        if ($brace !== false && str_ends_with(rtrim($message), '}')) {
            $context = trim(substr($message, $brace));
            $message = trim(substr($message, 0, $brace));
        }

        return [
            'time'    => (string) $matches['time'],
            'level'   => strtolower((string) $matches['level']),
            'channel' => (string) $matches['channel'],
            'message' => $message,
            'context' => $context,
        ];
    }
}
