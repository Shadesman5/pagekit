<?php

declare(strict_types=1);

namespace Pagekit\Site\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validation DTO for the Menu API payload.
 *
 * Menus are persisted in the `system/site` config store rather than the ORM,
 * so this is intentionally a plain value object (NOT a Doctrine entity). It
 * exists solely to carry the request data through Symfony Validator via
 * {@see \Pagekit\System\Controller\ValidatesRequestTrait}, matching the
 * attribute-based validation pattern used by the module's entity controllers.
 *
 * The `Delete` group validates the identifier alone (deletes have no label).
 */
final class Menu
{
    #[Assert\NotBlank(message: 'validation.menu.id_required', groups: ['Default', 'Delete'])]
    #[Assert\Regex(
        pattern: '/^[a-z0-9\-_]+$/',
        message: 'validation.menu.id_invalid',
        groups: ['Default', 'Delete']
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.menu.id_max_length',
        groups: ['Default', 'Delete']
    )]
    public ?string $id = null;

    #[Assert\NotBlank(message: 'validation.menu.label_required')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'validation.menu.label_max_length'
    )]
    public ?string $label = null;
}
