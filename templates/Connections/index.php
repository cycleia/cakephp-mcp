<?php
/**
 * The administrator's approved AI connections.
 *
 * @var \Cake\View\View $this
 * @var string $siteName
 * @var string $mcpUrl
 * @var array<\Cake\Datasource\EntityInterface> $grants
 * @var string $revokeUrl
 */
$this->assign('title', 'AI connections');
?>
<div class="cake-mcp" style="max-width:48rem;margin:2rem auto;padding:0 1rem;line-height:1.5">
    <h1 style="font-size:1.5rem">AI connections</h1>
    <p>
        Apps you have approved to use <?= h($siteName) ?>'s MCP server at <code><?= h($mcpUrl) ?></code>.
        Revoking a connection stops it immediately; the app will ask you to approve it again.
    </p>

    <?php if (!$grants) : ?>
        <p><em>No active connections.</em></p>
    <?php else : ?>
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="text-align:left">App</th>
                    <th style="text-align:left">Approved</th>
                    <th style="text-align:left">Last used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grants as $grant) : ?>
                <?php $client = $grant->get('mcp_client'); ?>
                <tr>
                    <td><?= h($client ? $client->get('client_name') : 'Unknown app') ?></td>
                    <td><?= h($grant->get('created') ? $grant->get('created')->format('Y-m-d H:i') : '') ?></td>
                    <td><?= h($grant->get('last_used') ? $grant->get('last_used')->format('Y-m-d H:i') : 'never') ?></td>
                    <td style="text-align:right">
                        <?= $this->Form->create(null, ['url' => $revokeUrl]) ?>
                        <?= $this->Form->hidden('grant_id', ['value' => $grant->get('id')]) ?>
                        <?= $this->Form->button('Revoke', ['type' => 'submit', 'class' => 'button secondary']) ?>
                        <?= $this->Form->end() ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
