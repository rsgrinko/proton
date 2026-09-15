<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\SignedUrl;

/**
 * Выгрузка готова — вот ссылка.
 *
 * Ссылка подписанная и со сроком: файл лежит вне public, скачать его можно
 * только по ней, и она перестаёт работать вместе с самим файлом.
 */
final class ExportReadyNotification extends Notification
{
    public function __construct(
        private string $label,
        private string $file,
        private int $rows
    ) {
    }

    public function type(): string
    {
        return 'export.ready';
    }

    public function title(): string
    {
        return 'Выгрузка «' . $this->label . '» готова';
    }

    public function body(): string
    {
        $days = max(1, (int) Config::get('export.keep_days', 7));

        return 'Строк в файле: ' . $this->rows . '. Ссылка действует ' . $days
            . ' дн., потом файл удаляется вместе с ней.';
    }

    public function url(): string
    {
        $days = max(1, (int) Config::get('export.keep_days', 7));

        return SignedUrl::absolute('exports.download', ['file' => $this->file], $days * 86400);
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
