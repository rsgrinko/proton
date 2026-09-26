<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Access\AccessDenied;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Support\Audit;

/**
 * Роли: наборы прав под именем.
 *
 * Права встроенной роли не меняются и удалить её нельзя — иначе система
 * останется без хозяина.
 */
final class RolesController extends Controller
{
    public function index(Request $request): Response
    {
        $page = Role::query()->orderBy('id')->paginate($this->page($request), $this->perPage());

        return $this->view('admin/roles', [
            'active' => 'roles',
            'roles'  => $page['items'],
            'page'   => $page,
        ], 'Роли');
    }

    public function create(): Response
    {
        return $this->view('admin/role', [
            'active' => 'roles',
            'role'   => new Role(),
            'groups' => Permission::groups(),
        ], 'Новая роль');
    }

    public function store(Request $request, Viewer $viewer): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|max:100|unique:roles,name',
            'description' => 'nullable|max:255',
        ], ['name' => 'Название', 'description' => 'Описание']);

        $permissions = Permission::filter((array) $request->input('permissions', []));

        $this->assertGrantable($viewer, $permissions);

        $role = new Role();

        $role->forceFill([
            'name'        => (string) $data['name'],
            'description' => (string) ($data['description'] ?? ''),
            'permissions' => $permissions,
            'is_system'   => 0,
        ])->save();

        Audit::created('role', $role->id(), 'заведена роль «' . (string) $role->name . '»');

        $this->flash('Роль создана');

        return $this->redirect('admin.roles.show', ['id' => $role->id()]);
    }

    public function show(int $id): Response
    {
        /** @var Role $role */
        $role = $this->require(Role::find($id), 'admin.roles', 'Роль не найдена');

        return $this->view('admin/role', [
            'active' => 'roles',
            'role'   => $role,
            'groups' => Permission::groups(),
        ], (string) $role->name);
    }

    public function update(Request $request, int $id, Viewer $viewer): Response
    {
        /** @var Role $role */
        $role = $this->require(Role::find($id), 'admin.roles', 'Роль не найдена');

        // И то, что в роли уже есть, и то, что в неё просят положить
        $this->assertGrantable($viewer, $role->permissions());

        $data = $this->validate($request, [
            'name'        => 'required|max:100|unique:roles,name,' . $role->id(),
            'description' => 'nullable|max:255',
        ], ['name' => 'Название', 'description' => 'Описание']);

        $before = ['name' => $role->name, 'permissions' => $role->permissions()];

        $role->forceFill([
            'name'        => (string) $data['name'],
            'description' => (string) ($data['description'] ?? ''),
        ]);

        // У встроенной роли права берутся из кода — форма их не показывает
        // и не меняет
        if (!$role->isSystem()) {
            $permissions = Permission::filter((array) $request->input('permissions', []));

            $this->assertGrantable($viewer, $permissions);

            $role->setAttribute('permissions', $permissions);
        }

        $role->save();

        Audit::updated('role', $role->id(), 'изменена роль «' . (string) $role->name . '»', Audit::between($before, [
            'name'        => $role->name,
            'permissions' => $role->permissions(),
        ]));

        $this->flash('Роль сохранена');

        return $this->redirect('admin.roles.show', ['id' => $role->id()]);
    }

    public function delete(int $id, Viewer $viewer): Response
    {
        /** @var Role $role */
        $role = $this->require(Role::find($id), 'admin.roles', 'Роль не найдена');

        $this->assertGrantable($viewer, $role->permissions());

        if ($role->isSystem()) {
            $this->flash('Встроенную роль удалить нельзя', 'error');

            return $this->redirect('admin.roles.show', ['id' => $role->id()]);
        }

        if ($role->usersCount() > 0) {
            $this->flash('Роль выдана пользователям — сначала переведите их на другую', 'error');

            return $this->redirect('admin.roles.show', ['id' => $role->id()]);
        }

        $name = (string) $role->name;

        $role->delete();

        Audit::deleted('role', $id, 'удалена роль «' . $name . '»');

        $this->flash('Роль удалена');

        return $this->redirect('admin.roles');
    }

    /**
     * Раздавать можно только свои права: иначе roles.manage дописал бы своей
     * роли что угодно и стал администратором. См. Viewer::covers().
     *
     * @param array<int, string> $permissions
     */
    private function assertGrantable(Viewer $viewer, array $permissions): void
    {
        if (!$viewer->covers($permissions)) {
            $missing = array_values(array_diff($permissions, $viewer->permissions()));

            throw new AccessDenied('Нельзя выдавать права, которых нет у вас самих: ' . implode(', ', $missing));
        }
    }
}
