<?php declare(strict_types=1); $view->script('storage-init') ?>

<div id="storage" v-cloak>
    <panel-finder root="<?= htmlentities($root) ?>" mode="<?= $mode ?>"></panel-finder>
</div>
