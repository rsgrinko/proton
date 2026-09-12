<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Validator;
use Rsgrinko\Proton\View\View;

/**
 * Основа контроллера: короткие помощники для того, что делается в каждом
 * действии, — отрисовать страницу, проверить данные, вернуть человека назад.
 *
 * Зависимости контроллер получает через конструктор (их собирает контейнер),
 * а не создаёт сам: тогда в тестах можно подложить свои.
 */
abstract class Controller
{
    /**
     * Страница внутри каркаса.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], string $title = ''): Response
    {
        // Заполненная форма и ошибки после неудачной проверки — их положило ядро
        $data['old']    = $data['old'] ?? (array) View::takeStash('old', []);
        $data['errors'] = $data['errors'] ?? (array) View::takeStash('errors', []);

        return View::page($template, $data, $title);
    }

    /**
     * Страница без шапки и меню — вход, регистрация.
     *
     * @param array<string, mixed> $data
     */
    protected function bare(string $template, array $data = [], string $title = ''): Response
    {
        $data['old']    = $data['old'] ?? (array) View::takeStash('old', []);
        $data['errors'] = $data['errors'] ?? (array) View::takeStash('errors', []);

        return Response::html(View::renderBare($template, $data, $title), 200);
    }

    /**
     * Перенаправление по имени маршрута.
     *
     * @param array<string, mixed> $params
     */
    protected function redirect(string $route, array $params = []): Response
    {
        return Response::redirect(View::route($route, $params));
    }

    /**
     * Назад, откуда пришли. Referer может не прийти вовсе — тогда на главную.
     */
    protected function back(Request $request): Response
    {
        $referer = $request->header('referer');

        return Response::redirect($referer !== '' ? $referer : View::route('home'));
    }

    /**
     * Сообщение на следующей странице.
     */
    protected function flash(string $message, string $type = 'ok'): void
    {
        View::flash($message, $type);
    }

    /**
     * Проверка данных запроса. Не прошла — исключение, которое ядро превратит
     * в возврат на форму с сообщениями (или в 422 для API).
     *
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     *
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        return Validator::make($request->all(), $rules, $labels)->validate();
    }

    /**
     * Запись обязана быть, иначе — «не найдено» с возвратом в список.
     *
     * @template T of Model
     *
     * @param T|null $record
     *
     * @return T
     */
    protected function require(?Model $record, string $route = '', string $message = 'Запись не найдена'): Model
    {
        if ($record === null) {
            throw new RecordNotFound($message, $route);
        }

        return $record;
    }

    /**
     * Сколько записей показывать на странице.
     */
    protected function perPage(): int
    {
        return max(5, (int) Config::get('ui.per_page', 25));
    }

    /**
     * Номер страницы из запроса.
     */
    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }
}
