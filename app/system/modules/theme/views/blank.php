<?php declare(strict_types=1); ?>
<html lang="<?= str_replace('_', '-', $app->get('translator')->getLocale()) ?>">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?= $view->render('head') ?>
    </head>
    <body>
        <?= $view->render('content') ?>
    </body>
</html>
