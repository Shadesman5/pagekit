<?php

declare(strict_types=1);

use Pagekit\Markdown\Markdown;

return [

    'main' => function ($app) {

        $app->set('markdown', fn () => new Markdown());

    },

];
