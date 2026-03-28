<?php

$image = $params->get('logo');
$attrs_link = [];
$attrs_image = [];

// Logo Text
$title = $params->get('title');

// Link
$attrs_link['href'] = $view->url()->get();
$attrs_link['class'][] = isset($class) ? $class : '';
$attrs_link['class'][] = 'uk-logo';

// Image
if ($image) {
    $attrs_image['class'][] = isset($img) ? $img : '';
    $attrs_image['class'][] = 'uk-border-circle uk-margin-small-right uk-object-cover'; // Ensure the image is circular
    $attrs_image['alt'] = $title;
    $attrs_image['width'] = '36';
    $attrs_image['height'] = '36';

    $logo_img = '<img src="'.$view->url()->getStatic($image).'" alt="'.htmlspecialchars($title).'" '.attrs($attrs_image).'>';
} else {
    $logo_img = '';
}

// Combine image and title text
$logo_content = '<div class="uk-flex uk-flex-middle">';
$logo_content .= $logo_img;
$logo_content .= '<span class="uk-text-bold">'.htmlspecialchars($title).'</span>';
$logo_content .= '</div>';
?>

<a<?= attrs($attrs_link) ?>>
    <?= $logo_content ?>
</a>
