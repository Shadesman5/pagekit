<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $menu->title ?></title>
    <link href="<?= $view->url()->getStatic('app/assets/uikit/dist/css/uikit.min.css') ?>" rel="stylesheet">
    <style>
        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .menucard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            text-align: center;
            margin-bottom: 2rem;
        }
        .menucard-title {
            font-size: 3rem;
            font-weight: 700;
            margin: 0;
        }
        .menucard-description {
            font-size: 1.2rem;
            margin-top: 1rem;
            opacity: 0.9;
        }
        .category {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .category-title {
            font-size: 2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
            border-bottom: 3px solid #667eea;
            padding-bottom: 0.5rem;
        }
        .category-description {
            color: #666;
            margin-bottom: 1.5rem;
            font-style: italic;
        }
        .product {
            padding: 1rem 0;
            border-bottom: 1px solid #e5e5e5;
        }
        .product:last-child {
            border-bottom: none;
        }
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .product-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
        }
        .product-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: #667eea;
        }
        .product-description {
            color: #666;
            margin-top: 0.5rem;
        }
        .product-allergens {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #999;
        }
        .allergen-label {
            font-weight: 600;
            color: #d9534f;
        }
    </style>
</head>
<body>
    <div class="menucard-header">
        <div class="uk-container uk-container-center">
            <h1 class="menucard-title"><?= $menu->title ?></h1>
            <?php if ($menu->description): ?>
                <p class="menucard-description"><?= nl2br($app->escape($menu->description)) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="uk-container uk-container-center">
        <?php foreach ($menu->categories as $category): ?>
            <div class="category">
                <h2 class="category-title"><?= $category->title ?></h2>
                
                <?php if ($category->description): ?>
                    <p class="category-description"><?= nl2br($app->escape($category->description)) ?></p>
                <?php endif; ?>

                <?php if ($category->products): ?>
                    <?php foreach ($category->products as $product): ?>
                        <div class="product">
                            <div class="product-header">
                                <span class="product-name"><?= $product->name ?></span>
                                <?php if ($product->price): ?>
                                    <span class="product-price"><?= number_format($product->price, 2, $config['decimal_separator'], $config['thousands_separator']) ?> <?= $config['currency'] ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($product->description): ?>
                                <div class="product-description"><?= nl2br($app->escape($product->description)) ?></div>
                            <?php endif; ?>
                            
                            <?php if ($product->allergens): ?>
                                <div class="product-allergens">
                                    <span class="allergen-label">Allergens:</span> <?= $app->escape($product->allergens) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="uk-container uk-container-center uk-margin-large-top uk-margin-large-bottom uk-text-center">
        <p class="uk-text-muted">
            Powered by <a href="https://pagekit.com" target="_blank">Pagekit</a> Menucards Extension
        </p>
    </div>
</body>
</html>
