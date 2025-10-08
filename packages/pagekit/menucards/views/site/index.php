<div class="uk-container uk-container-center uk-margin-large-top uk-margin-large-bottom">
    <h1 class="uk-heading-primary">Our Menu Cards</h1>

    <?php if (empty($menus)): ?>
        <div class="uk-alert">
            <p>No menu cards available at the moment.</p>
        </div>
    <?php else: ?>
        <div class="uk-grid uk-grid-medium uk-child-width-1-2@m uk-child-width-1-3@l" data-uk-grid>
            <?php foreach ($menus as $menu): ?>
                <div>
                    <div class="uk-card uk-card-default uk-card-hover uk-card-body">
                        <h3 class="uk-card-title">
                            <a href="<?= $view->url('@menucards/site/view', ['slug' => $menu->slug]) ?>">
                                <?= $this->escape($menu->title) ?>
                            </a>
                        </h3>
                        <?php if ($menu->description): ?>
                            <p class="uk-text-muted"><?= $this->escape($menu->description) ?></p>
                        <?php endif; ?>
                        <p>
                            <a href="<?= $view->url('@menucards/site/view', ['slug' => $menu->slug]) ?>" 
                               class="uk-button uk-button-primary uk-button-small">
                                View Menu
                            </a>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
