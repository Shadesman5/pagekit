<?php

namespace Pagekit\Database;

final class Events
{
    /**
     * This event occurs after an entity is loaded.
     *
     * @var string
     */
    public const INIT = 'init';

    /**
     * This event occurs before an entity is saved.
     *
     * @var string
     */
    public const SAVING = 'saving';

    /**
     * This event occurs after an entity is saved.
     *
     * @var string
     */
    public const SAVED = 'saved';

    /**
     * This event occurs before a new entity is saved.
     *
     * @var string
     */
    public const CREATING = 'creating';

    /**
     * This event occurs after a new entity is saved.
     *
     * @var string
     */
    public const CREATED = 'created';

    /**
     * This event occurs before an existing entity is updated.
     *
     * @var string
     */
    public const UPDATING = 'updating';

    /**
     * This event occurs after an existing entity is updated.
     *
     * @var string
     */
    public const UPDATED = 'updated';

    /**
     * This event occurs before an existing entity is deleted.
     *
     * @var string
     */
    public const DELETING = 'deleting';

    /**
     * This event occurs after an existing entity is deleted.
     *
     * @var string
     */
    public const DELETED = 'deleted';
}
