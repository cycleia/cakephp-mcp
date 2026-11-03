<?php
/**
 * Shown to signed-in users who are not administrators.
 *
 * @var \Cake\View\View $this
 * @var string $siteName
 * @var string $clientName
 * @var string|null $userName
 * @var string $returnUrl
 */
$this->assign('title', 'Administrators only');
?>
<div class="cake-mcp" style="max-width:36rem;margin:2rem auto;padding:0 1rem;line-height:1.5">
    <h1 style="font-size:1.5rem">Administrators only</h1>
    <p>
        Only administrators of <?= h($siteName) ?> can connect AI apps such as <?= h($clientName) ?>.
        <?php if ($userName) : ?>
            You are signed in as <strong><?= h($userName) ?></strong>.
        <?php endif; ?>
    </p>
    <p><a href="<?= h($returnUrl) ?>">Return to <?= h($clientName) ?></a></p>
</div>
