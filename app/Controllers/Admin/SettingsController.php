<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Settings;

/**
 * Настройки приложения: то, что меняют на ходу, без правки `.env` и выкладки.
 *
 * Значение из базы ложится поверх `.env`. «Сбросить» — значит удалить значение
 * из базы, и настройка снова берётся из `.env`; именно поэтому сброс и правка
 * разведены по разным кнопкам.
 */
final class SettingsController extends Controller
{
    public function index(): Response
    {
        return $this->view('admin/settings', [
            'active' => 'settings',
            'groups' => Settings::groups(),
            'values' => $this->current(),
        ], 'Настройки');
    }

    /**
     * Сохраняет весь набор разом: в форме он один, и правка пары полей не должна
     * требовать отдельной кнопки у каждого.
     */
    public function update(Request $request): Response
    {
        $sent    = (array) $request->input('settings', []);
        // Какие настройки форма вообще показывала: снятая галочка не приходит
        // вовсе, и без этого списка её не отличить от настройки, которой в форме
        // не было, — частичная форма выключала бы всё остальное
        $shown   = array_map(static fn (mixed $key): string => (string) $key, (array) $request->input('shown', []));
        $changed = [];
        $before  = $this->current();

        foreach (Settings::groups() as $items) {
            foreach ($items as $key => $item) {
                if (!in_array($key, $shown, true) && !array_key_exists($key, $sent)) {
                    continue;
                }

                $value = $item['type'] === Settings::BOOL
                    ? (isset($sent[$key]) ? '1' : '0')
                    : ($sent[$key] ?? null);

                if ($value === null) {
                    continue;
                }

                $problem = Settings::problem($key, $value);

                if ($problem !== '') {
                    $this->flash('«' . $item['label'] . '»: ' . $problem, 'error');

                    return $this->redirect('admin.settings');
                }

                if ((string) $value === (string) $this->text($before[$key] ?? null)) {
                    continue;
                }

                Settings::put($key, $value);

                $changed[$key] = $value;
            }
        }

        if ($changed === []) {
            $this->flash('Менять нечего: значения те же');

            return $this->redirect('admin.settings');
        }

        $this->writeAudit($changed, $before);

        $this->flash('Сохранено настроек: ' . count($changed));

        return $this->redirect('admin.settings');
    }

    /**
     * Возвращает настройку к значению из `.env`.
     */
    public function reset(Request $request): Response
    {
        $key = trim((string) $request->input('key', ''));

        if (!Settings::known($key)) {
            $this->flash('Неизвестная настройка', 'error');

            return $this->redirect('admin.settings');
        }

        Settings::forget($key);

        Audit::action('settings', $key, 'настройка ' . $key . ' возвращена к значению из .env');

        $this->flash('Настройка сброшена: теперь работает значение из .env');

        return $this->redirect('admin.settings');
    }

    /**
     * Выгружает .env таким, каким он был бы с уже применёнными правками из
     * панели, — переносить их на сервер файлом, а не проставлять в форме заново.
     */
    public function exportEnv(): Response
    {
        Audit::action('settings', 'export-env', 'выгружен .env с настройками из панели');

        return Response::download(Settings::exportEnv(), '.env', 'text/plain; charset=utf-8');
    }

    /**
     * Текущие значения всех настроек реестра.
     *
     * @return array<string, mixed>
     */
    private function current(): array
    {
        $values = [];

        foreach (Settings::groups() as $items) {
            foreach (array_keys($items) as $key) {
                $values[$key] = Settings::value($key);
            }
        }

        return $values;
    }

    /**
     * Запись в журнал: секреты в него не попадают ни в каком виде.
     *
     * @param array<string, mixed> $changed
     * @param array<string, mixed> $before
     */
    private function writeAudit(array $changed, array $before): void
    {
        $changes = [];

        foreach ($changed as $key => $value) {
            $secret = (Settings::describe($key)['type'] ?? '') === Settings::SECRET;

            $changes[$key] = $secret
                ? ['изменено', 'изменено']
                : [$this->text($before[$key] ?? null), $this->text($value)];
        }

        Audit::updated('settings', 'config', 'изменены настройки: ' . implode(', ', array_keys($changed)), $changes);
    }

    /**
     * Значение в виде строки для сравнения и журнала.
     */
    private function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value === null ? '' : (string) $value;
    }
}
