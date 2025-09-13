<?php $view->style('hello', 'hello:app/assets/css/hello.css') ?>

<div id="frontend-hello">
    <h1 class="uk-heading-small">
        Hello <?= count($names) == 1 ? $names[0] : "to the ".count($names). " of you"; ?>
    </h1>

    <p><?= _c("{0}: No names|one: One name|more: %names% names", count($names), ["%names%" => count($names)]) ?></p>

    <?php foreach ($names as $name): ?>
        <div class="uk-alert-primary uk-alert" uk-alert>
            <p><?= __("Hello %name%!", ["%name%" => $name]) ?></p>
        </div>
    <?php endforeach ?>
</div>
