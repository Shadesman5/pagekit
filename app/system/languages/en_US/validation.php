<?php

/**
 * Validation Messages for Symfony Validator (Step 1.13 - Hybrid Mode)
 *
 * This file contains all validation message keys used with Symfony Validator attributes.
 * These keys are referenced in entity validation constraints via the 'message' parameter.
 *
 * Example usage in entity:
 * #[Assert\NotBlank(message: 'validation.user.username_required')]
 *
 * Note: Actual translation integration (using __() function or Symfony Translator)
 * will be handled in a later phase. For now, these are English messages only.
 */

return [

    // ============================================================
    // GENERIC VALIDATION MESSAGES
    // ============================================================

    'validation.required' => 'This field is required.',
    'validation.email' => 'This value is not a valid email address.',
    'validation.url' => 'This value is not a valid URL.',
    'validation.min_length' => 'This value is too short. It should have {{ limit }} characters or more.',
    'validation.max_length' => 'This value is too long. It should have {{ limit }} characters or fewer.',
    'validation.length' => 'This value should be between {{ min }} and {{ max }} characters.',
    'validation.unique' => 'This value already exists.',
    'validation.regex' => 'This value is not valid.',
    'validation.choice' => 'The value you selected is not a valid choice.',
    'validation.positive_or_zero' => 'This value should be either positive or zero.',

    // ============================================================
    // USER MODULE
    // ============================================================

    // User Entity
    'validation.user.username_required' => 'Username is required.',
    'validation.user.username_min_length' => 'Username must be at least {{ limit }} characters.',
    'validation.user.username_max_length' => 'Username cannot exceed {{ limit }} characters.',
    'validation.user.username_invalid' => 'Username is invalid. Only letters, numbers, dots, underscores and hyphens are allowed.',
    'validation.user.username_not_available' => 'Username is not available.',

    'validation.user.password_required' => 'Password is required.',

    'validation.user.email_required' => 'Email is required.',
    'validation.user.email_invalid' => 'Email is invalid.',
    'validation.user.email_not_available' => 'Email is not available.',

    'validation.user.name_required' => 'Name is required.',
    'validation.user.name_max_length' => 'Name cannot exceed {{ limit }} characters.',

    'validation.user.url_invalid' => 'URL is invalid.',

    'validation.user.status_invalid' => 'Invalid status.',

    // Role Entity
    'validation.role.name_required' => 'Role name is required.',
    'validation.role.name_min_length' => 'Role name must be at least {{ limit }} characters.',
    'validation.role.name_max_length' => 'Role name cannot exceed {{ limit }} characters.',
    'validation.role.priority_invalid' => 'Priority must be a non-negative number.',

    // ============================================================
    // SITE MODULE
    // ============================================================

    // Node Entity
    'validation.node.slug_required' => 'Slug is required.',
    'validation.node.slug_invalid' => 'Invalid slug. Only lowercase letters, numbers, hyphens and underscores are allowed.',
    'validation.node.slug_max_length' => 'Slug cannot exceed {{ limit }} characters.',

    'validation.node.title_required' => 'Title is required.',
    'validation.node.title_max_length' => 'Title cannot exceed {{ limit }} characters.',

    'validation.node.link_max_length' => 'Link cannot exceed {{ limit }} characters.',

    'validation.node.type_required' => 'Node type is required.',

    'validation.node.status_invalid' => 'Invalid status.',

    'validation.node.priority_invalid' => 'Priority must be a non-negative number.',

    // Page Entity
    'validation.page.title_required' => 'Page title is required.',
    'validation.page.title_max_length' => 'Page title cannot exceed {{ limit }} characters.',

    // Menu (not entity validation, but business logic messages)
    'validation.menu.id_invalid' => 'Invalid menu id.',

    // ============================================================
    // WIDGET MODULE
    // ============================================================

    // Widget Entity
    'validation.widget.title_required' => 'Widget title is required.',
    'validation.widget.title_max_length' => 'Widget title cannot exceed {{ limit }} characters.',

    'validation.widget.type_required' => 'Widget type is required.',

    'validation.widget.status_invalid' => 'Invalid widget status.',

];
