<?php
/**
 * @var \Cake\View\View $this
 * @var string $title
 * @var string $message
 * @var string $siteName
 */
$this->assign('title', $title);
?>
<div class="cake-mcp" style="max-width:36rem;margin:2rem auto;padding:0 1rem;line-height:1.5">
    <h1 style="font-size:1.5rem"><?= h($title) ?></h1>
    <p><?= h($message) ?></p>
</div>
