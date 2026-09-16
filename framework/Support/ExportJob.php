<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Job;

/**
 * Готовит большую выгрузку в фоне — первый шаг цепочки, второй (письмо
 * заказчику) забирает NotifyExportReadyJob, см. Queue::chain() в
 * Controller::exportCsv().
 *
 * Запрос задаче не передашь — в очереди лежит JSON. Поэтому передаются имя
 * выгрузки из реестра (`Exports`) и параметры отбора, а запрос собирается
 * заново уже в воркере, от лица того, кто выгрузку заказал: чужие записи
 * не должны уехать в файл из-за того, что считает их воркер.
 */
final class ExportJob extends Job
{
    public function attempts(): int
    {
        return 2;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $key    = (string) ($payload['kind'] ?? '');
        $params = (array) ($payload['params'] ?? []);
        $userId = (int) ($payload['user_id'] ?? 0);

        $kind = Exports::get($key);
        $user = User::find($userId);

        if ($kind === null || $user === null) {
            (new Logger('export'))->warning('Выгрузку некому отдать', ['kind' => $key, 'user' => $userId]);

            return;
        }

        $viewer = Viewer::fromUser($user);
        $query  = Exports::query($key, array_map('strval', $params), $viewer);

        if ($query === null) {
            return;
        }

        $content = Csv::write(
            ExportFile::rows($query, $kind['columns']),
            ExportFile::headers($kind['columns'])
        );

        $name = ExportFile::save($key, $content);

        Audit::action('export', 0, 'подготовлена выгрузка «' . $kind['label'] . '»: ' . $name);

        // Имя файла и число строк известны только теперь — второй шаг цепочки
        // получит их через carryToNext(), а не заранее в payload
        $this->carryToNext([
            'user_id' => $userId,
            'label'   => $kind['label'],
            'name'    => $name,
            'rows'    => ExportFile::rowCount($content),
        ]);
    }
}
