<?php declare(strict_types=1); foreach ($widgets as $widget) : ?>

    <?= getHTML($widget->get('result')) ?>

<?php endforeach ?>
