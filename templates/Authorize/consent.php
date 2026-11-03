<?php
/**
 * Approval page. Override per site at templates/plugin/CakeMcp/Authorize/consent.php.
 *
 * @var \Cake\View\View $this
 * @var string $siteName
 * @var string $clientName
 * @var string $redirectHost
 * @var bool $isLoopback
 * @var string $userName
 * @var string $requestId
 * @var string $formUrl
 * @var string $connectionsUrl
 */
$this->assign('title', 'Connect ' . $clientName);
?>
<div class="cake-mcp" style="max-width:36rem;margin:2rem auto;padding:0 1rem;line-height:1.5">
    <h1 style="font-size:1.5rem">Connect <?= h($clientName) ?> to <?= h($siteName) ?>?</h1>

    <p>
        <strong><?= h($clientName) ?></strong> wants to use <?= h($siteName) ?>'s MCP tools
        as <strong><?= h($userName) ?></strong>, with your administrator access.
    </p>

    <p>
        After you approve, you will be sent back to
        <strong><?= h($isLoopback ? 'an app on your own computer (' . $redirectHost . ')' : $redirectHost) ?></strong>.
        <?php if ($isLoopback) : ?>
            Approve only if you just started this connection yourself, from Claude Code, Codex or a similar app.
        <?php else : ?>
            Approve only if you just started this connection yourself.
        <?php endif; ?>
    </p>

    <p style="font-size:.9em;opacity:.8">
        The app name is what the app said about itself. You can revoke this connection at any time
        under <a href="<?= h($this->Url->build($connectionsUrl)) ?>">AI connections</a>.
    </p>

    <?= $this->Form->create(null, ['url' => $formUrl]) ?>
    <?= $this->Form->hidden('request_id', ['value' => $requestId]) ?>
    <div style="display:flex;gap:.75rem;margin-top:1.5rem">
        <?= $this->Form->button('Approve', ['type' => 'submit', 'name' => 'decision', 'value' => 'approve', 'class' => 'button']) ?>
        <?= $this->Form->button('Cancel', ['type' => 'submit', 'name' => 'decision', 'value' => 'deny', 'class' => 'button secondary']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
