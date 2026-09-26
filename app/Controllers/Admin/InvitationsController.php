<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Access\AccessDenied;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Models\Invitation;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Filter;

/**
 * Приглашения: письмо со ссылкой, по которой человек сам заводит себе
 * учётную запись с выбранной здесь ролью.
 *
 * Роль проверяется так же, как при заведении пользователя руками: выдать
 * через приглашение больше своих прав нельзя, иначе это был бы обход
 * Viewer::covers() в два шага.
 */
final class InvitationsController extends Controller
{
    public function index(Request $request, Viewer $viewer): Response
    {
        $roles = [];

        foreach (Role::all() as $role) {
            $roles[$role->id()] = (string) $role->name;
        }

        $filters = $this->filters($request, [
            Filter::search('q', 'Поиск', ['email'], 'почта'),
            Filter::select('role_id', 'Роль', $roles),
        ])->sortable(['id', 'email', 'expires_at'], 'id');

        $page = $filters->apply(Invitation::query())->paginate($this->page($request), $this->perPage());

        // Логины приглашавших — одним запросом на страницу, а не по строке
        $ids      = array_filter(array_map(static fn (Invitation $i): int => (int) $i->raw('invited_by'), $page['items']));
        $inviters = [];

        if ($ids !== []) {
            foreach (User::query()->withTrashed()->whereIn('id', array_values(array_unique($ids)))->get() as $user) {
                $inviters[$user->id()] = (string) $user->login;
            }
        }

        return $this->view('admin/invitations', [
            'active'     => 'invitations',
            'page'       => $page,
            'roles'      => $roles,
            'assignable' => Role::assignableBy($viewer),
            'inviters'   => $inviters,
            'filters'    => $filters,
            'days'       => intdiv(Invitation::lifetime(), 86400),
        ], 'Приглашения');
    }

    public function store(Request $request, User $user, Viewer $viewer): Response
    {
        $data = $this->validate($request, [
            'email'   => 'required|email|max:191',
            'role_id' => 'required|integer|exists:roles',
        ], ['email' => 'Почта', 'role_id' => 'Роль']);

        $email = mb_strtolower(trim((string) $data['email']), 'UTF-8');

        // unique:users,email тут не годится: он сравнивает как есть, а адрес
        // в базе мог лечь с другим регистром
        if (User::query()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->count() > 0) {
            $this->flash('Пользователь с адресом ' . $email . ' уже есть', 'error');

            return $this->redirect('admin.invitations');
        }

        $role = $this->assignable($viewer, (int) $data['role_id']);

        $sent = Invitation::issue($email, $role->id(), $user->id());

        $this->send($sent, $user);

        Audit::created('invitation', $sent['invitation']->id(), 'приглашение на ' . $email . ' с ролью «' . (string) $role->name . '»');

        $this->flash('Приглашение отправлено на ' . $email);

        return $this->redirect('admin.invitations');
    }

    /**
     * Отправить заново: новая ссылка и новый срок, прежняя ссылка гаснет.
     * Годится и для истёкшего, и для отозванного — отправляют ведь тому же
     * человеку, а не заводят новое.
     */
    public function resend(int $id, User $user, Viewer $viewer): Response
    {
        /** @var Invitation $invitation */
        $invitation = $this->require(Invitation::find($id), 'admin.invitations', 'Приглашение не найдено');

        if ($invitation->state() === Invitation::ACCEPTED) {
            $this->flash('Приглашение уже принято', 'error');

            return $this->redirect('admin.invitations');
        }

        $role = $this->assignable($viewer, (int) $invitation->raw('role_id'));

        $this->send(Invitation::issue((string) $invitation->email, $role->id(), $user->id()), $user);

        Audit::action('invitation', $invitation->id(), 'приглашение на ' . (string) $invitation->email . ' отправлено заново');

        $this->flash('Приглашение отправлено заново');

        return $this->redirect('admin.invitations');
    }

    public function revoke(int $id): Response
    {
        /** @var Invitation $invitation */
        $invitation = $this->require(Invitation::find($id), 'admin.invitations', 'Приглашение не найдено');

        if (!$invitation->isPending()) {
            $this->flash('Отозвать можно только ждущее приглашение', 'error');

            return $this->redirect('admin.invitations');
        }

        $invitation->revoke();

        Audit::action('invitation', $invitation->id(), 'отозвано приглашение на ' . (string) $invitation->email);

        $this->flash('Приглашение отозвано');

        return $this->redirect('admin.invitations');
    }

    /**
     * Роль, которую этот человек вправе выдать. Проверяется и при повторной
     * отправке: права отправлявшего в первый раз могли быть шире.
     */
    private function assignable(Viewer $viewer, int $roleId): Role
    {
        $role = Role::find($roleId);

        if ($role === null) {
            throw new AccessDenied('Роли больше нет — выберите другую');
        }

        if (!$viewer->covers($role->permissions())) {
            throw new AccessDenied('Роль «' . (string) $role->name . '» даёт права, которых у вас нет — пригласить с ней нельзя');
        }

        return $role;
    }

    /**
     * Письмо — очередью: страница не ждёт чужой SMTP.
     *
     * @param array{invitation: Invitation, token: string} $sent
     */
    private function send(array $sent, User $inviter): void
    {
        $invitation = $sent['invitation'];

        Message::to((string) $invitation->email)
            ->subject('Приглашение в ' . (string) Config::get('app.name', 'Proton'))
            ->view('mail/invite', [
                'inviter' => $inviter,
                'role'    => $invitation->role(),
                'link'    => Router::absolute('invite', ['token' => $sent['token']]),
                'days'    => intdiv(Invitation::lifetime(), 86400),
            ])
            ->queue();
    }
}
