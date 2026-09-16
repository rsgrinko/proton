<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\UserField;
use Rsgrinko\Proton\Models\UserFieldValue;
use Rsgrinko\Proton\Support\Audit;

/**
 * Свои поля профиля: администратор заводит их здесь, без правки кода и
 * миграций, и поле сразу появляется в анкете у каждого пользователя.
 */
final class UserFieldsController extends Controller
{
    public function index(): Response
    {
        return $this->view('admin/user_fields', [
            'active' => 'user-fields',
            'fields' => UserField::allOrdered(),
        ], 'Поля профиля');
    }

    public function create(): Response
    {
        return $this->view('admin/user_field', [
            'active' => 'user-fields',
            'field'  => new UserField(),
            'types'  => UserField::types(),
        ], 'Новое поле профиля');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'label'       => 'required|max:191',
            'key'         => 'nullable|max:100|regex:/^[a-z][a-z0-9_]*$/|unique:user_fields,field_key',
            'type'        => 'required|in:' . implode(',', array_keys(UserField::types())),
            'description' => 'nullable|max:255',
            'sort'        => 'nullable|integer',
        ], ['label' => 'Название', 'key' => 'Ключ', 'type' => 'Тип', 'description' => 'Подсказка', 'sort' => 'Порядок']);

        $key = trim((string) ($data['key'] ?? ''));

        $field = new UserField();

        $field->forceFill([
            'field_key'   => $key !== '' ? $key : UserField::keyFrom((string) $data['label']),
            'label'       => (string) $data['label'],
            'type'        => (string) $data['type'],
            'description' => (string) ($data['description'] ?? ''),
            'sort'        => (int) ($data['sort'] ?? 0),
        ])->save();

        Audit::created('user_field', $field->id(), 'заведено поле профиля «' . (string) $field->label . '»');

        $this->flash('Поле заведено');

        return $this->redirect('admin.userFields');
    }

    public function show(int $id): Response
    {
        /** @var UserField $field */
        $field = $this->require(UserField::find($id), 'admin.userFields', 'Поле не найдено');

        return $this->view('admin/user_field', [
            'active' => 'user-fields',
            'field'  => $field,
            'types'  => UserField::types(),
        ], (string) $field->label);
    }

    public function update(Request $request, int $id): Response
    {
        /** @var UserField $field */
        $field = $this->require(UserField::find($id), 'admin.userFields', 'Поле не найдено');

        $data = $this->validate($request, [
            'label'       => 'required|max:191',
            'type'        => 'required|in:' . implode(',', array_keys(UserField::types())),
            'description' => 'nullable|max:255',
            'sort'        => 'nullable|integer',
        ], ['label' => 'Название', 'type' => 'Тип', 'description' => 'Подсказка', 'sort' => 'Порядок']);

        // Ключ не трогаем: на него могли сослаться снаружи (импорт, API) —
        // переименовать поле можно, не отрывая от него значения
        $before = ['label' => $field->label, 'type' => $field->type];

        $field->forceFill([
            'label'       => (string) $data['label'],
            'type'        => (string) $data['type'],
            'description' => (string) ($data['description'] ?? ''),
            'sort'        => (int) ($data['sort'] ?? 0),
        ])->save();

        Audit::updated('user_field', $field->id(), 'изменено поле профиля «' . (string) $field->label . '»', Audit::between($before, [
            'label' => $field->label,
            'type'  => $field->type,
        ]));

        $this->flash('Поле сохранено');

        return $this->redirect('admin.userFields.show', ['id' => $field->id()]);
    }

    public function delete(int $id): Response
    {
        /** @var UserField $field */
        $field = $this->require(UserField::find($id), 'admin.userFields', 'Поле не найдено');

        $label = (string) $field->label;

        // Без значений поле не значит ничего — убираем их первыми
        UserFieldValue::forgetField($field->id());

        $field->delete();

        Audit::deleted('user_field', $id, 'удалено поле профиля «' . $label . '»');

        $this->flash('Поле удалено вместе со значениями у всех пользователей');

        return $this->redirect('admin.userFields');
    }
}
