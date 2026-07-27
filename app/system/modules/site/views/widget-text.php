<?php declare(strict_types=1); ?>
<?= $app->get('content')->applyPlugins($widget->get('content'), ['widget' => $widget, 'markdown' => $widget->get('markdown')]);
