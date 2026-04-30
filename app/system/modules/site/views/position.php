<?php declare(strict_types=1); foreach ($widgets as $widget) : ?>
<div>

    <h3><?= $widget->title ?></h3>

    <?= $widget->get('result') ?>

</div>
<?php endforeach ?>
