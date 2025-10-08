<div class="uk-container uk-container-center uk-margin-large-top uk-margin-large-bottom">
    <!-- Menu Header -->
    <div class="uk-margin-large-bottom">
        <h1 class="uk-heading-primary"><?= $this->escape($menu->title) ?></h1>
        <?php if ($menu->description): ?>
            <p class="uk-text-lead uk-text-muted"><?= $this->escape($menu->description) ?></p>
        <?php endif; ?>
        <hr class="uk-divider-small">
    </div>

    <?php if (empty($categories)): ?>
        <div class="uk-alert uk-alert-warning">
            <p>This menu is currently being updated. Please check back soon!</p>
        </div>
    <?php else: ?>
        <!-- Categories and Products -->
        <?php foreach ($categories as $category): ?>
            <div class="uk-margin-large-bottom">
                <h2 class="uk-heading-line">
                    <span><?= $this->escape($category['title']) ?></span>
                </h2>

                <?php if (empty($category['products'])): ?>
                    <p class="uk-text-muted uk-text-small">No items in this category yet.</p>
                <?php else: ?>
                    <div class="uk-grid uk-grid-medium uk-child-width-1-1 uk-child-width-1-2@m uk-child-width-1-3@l" data-uk-grid>
                        <?php foreach ($category['products'] as $product): ?>
                            <div>
                                <div class="uk-card uk-card-default">
                                    <?php if ($product->image): ?>
                                        <div class="uk-card-media-top">
                                            <img src="<?= $this->escape($product->image) ?>" 
                                                 alt="<?= $this->escape($product->name) ?>"
                                                 class="uk-width-1-1">
                                        </div>
                                    <?php endif; ?>
                                    <div class="uk-card-body">
                                        <div class="uk-flex uk-flex-between uk-flex-middle">
                                            <h3 class="uk-card-title uk-margin-remove"><?= $this->escape($product->name) ?></h3>
                                            <span class="uk-badge uk-badge-success">€<?= number_format($product->price, 2) ?></span>
                                        </div>
                                        <?php if ($product->description): ?>
                                            <p class="uk-text-muted uk-margin-small-top">
                                                <?= $this->escape($product->description) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Back Link -->
    <div class="uk-margin-large-top">
        <a href="<?= $view->url('@menucards/site/index') ?>" class="uk-button uk-button-default">
            <span uk-icon="arrow-left"></span> Back to all menus
        </a>
    </div>
</div>
