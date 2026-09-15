<?php

declare(strict_types=1);

/**
 * Подозрительная активность и закрытые адреса.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\SecurityEvent>, total: int, page: int, pages: int, per_page: int} $page
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var array<int, \Rsgrinko\Proton\Models\BlockedIp> $blocks
 * @var array<string, int> $authors логины, которые чаще всего подбирают
 * @var array<string, int> $top самые шумные адреса за сутки
 */

use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\View\View;
?>
<h1>Безопасность</h1>

<div class="grid cols-2">
    <div class="card">
        <h2>Закрыть адрес</h2>

        <form method="post" action="<?= View::e(View::route('admin.security.block')) ?>">
            <?= View::csrf() ?>

            <div class="filters">
                <label>
                    <span>Адрес</span>
                    <input type="text" name="ip" placeholder="203.0.113.10" required>
                </label>

                <label>
                    <span>Причина</span>
                    <input type="text" name="reason" placeholder="перебор паролей">
                </label>

                <label>
                    <span>На сколько минут</span>
                    <input type="number" name="minutes" value="60" min="0">
                </label>
            </div>

            <div class="filter-actions row">
                <button type="submit" class="primary">Закрыть</button>
                <span class="muted small">0 минут — навсегда, пока не откроете руками</span>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Кого подбирают</h2>

        <?php if ($authors === [] && $top === []) { ?>
            <p class="muted small">Пока тихо.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table>
                    <?php foreach ($authors as $login => $count) { ?>
                        <tr>
                            <td><?= View::e((string) $login) ?></td>
                            <td class="right"><?= (int) $count ?> попыток</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

            <?php if ($top !== []) { ?>
                <h2 style="margin-top: 16px;">Шумные адреса за сутки</h2>

                <div class="table-wrap">
                    <table>
                        <?php foreach ($top as $ip => $count) { ?>
                            <tr>
                                <td class="mono small"><?= View::e((string) $ip) ?></td>
                                <td class="right"><?= (int) $count ?></td>
                                <td class="right">
                                    <form method="post" action="<?= View::e(View::route('admin.security.block')) ?>">
                                        <?= View::csrf() ?>
                                        <input type="hidden" name="ip" value="<?= View::e((string) $ip) ?>">
                                        <input type="hidden" name="reason" value="шум в журнале безопасности">
                                        <input type="hidden" name="minutes" value="60">
                                        <button type="submit">Закрыть на час</button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<div class="card">
    <h2>Закрытые адреса</h2>

    <?php if ($blocks === []) { ?>
        <p class="muted small">Ни один адрес не закрыт.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Адрес</th>
                    <th>Причина</th>
                    <th>До</th>
                    <th></th>
                </tr>

                <?php foreach ($blocks as $block) { ?>
                    <tr>
                        <td class="mono"><?= View::e((string) $block->raw('ip')) ?></td>
                        <td class="muted small"><?= View::e((string) $block->raw('reason') ?: '—') ?></td>
                        <td class="small">
                            <?php if ($block->permanent()) { ?>
                                <span class="badge warn">навсегда</span>
                            <?php } else { ?>
                                <?= View::e(View::date($block->until())) ?>
                            <?php } ?>
                        </td>
                        <td class="right">
                            <form method="post" action="<?= View::e(View::route('admin.security.unblock')) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="ip" value="<?= View::e((string) $block->raw('ip')) ?>">
                                <button type="submit">Открыть</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } ?>
</div>

<?= View::partial('filters', [
    'filters' => $filters,
    'route'   => 'admin.security',
    'total'   => $page['total'],
    'export'  => 'admin.security.export',
]) ?>

<div class="card">
    <h2>Что происходило</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted small">Событий нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.security', 'column' => 'created_at', 'label' => 'Когда']) ?></th>
                    <th>Событие</th>
                    <th>Адрес</th>
                    <th>Логин</th>
                    <th class="hide-sm">Страница</th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $event) { ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e(View::date((string) $event->raw('created_at'))) ?></td>
                        <td><span class="badge muted"><?= View::e(SecurityEvent::label((string) $event->raw('kind'))) ?></span></td>
                        <td class="mono small"><?= View::e((string) $event->raw('ip')) ?></td>
                        <td class="small"><?= View::e((string) $event->raw('login') ?: '—') ?></td>
                        <td class="hide-sm muted small"><?= View::e((string) $event->raw('path') ?: '—') ?></td>
                        <td class="right">
                            <?php if ((string) $event->raw('ip') !== '') { ?>
                                <form method="post" action="<?= View::e(View::route('admin.security.block')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="ip" value="<?= View::e((string) $event->raw('ip')) ?>">
                                    <input type="hidden" name="reason" value="<?= View::e(SecurityEvent::label((string) $event->raw('kind'))) ?>">
                                    <input type="hidden" name="minutes" value="60">
                                    <button type="submit" class="small">Закрыть</button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.security',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
