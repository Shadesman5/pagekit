<?php

/**
 * Blog Extension - Validation Messages (Symfony Validator)
 *
 * These messages are used with Symfony Validator attributes in Blog entities.
 * Message keys follow pattern: validation.{entity}.{field}_{constraint}
 */

return [

    // ============================================================
    // POST ENTITY
    // ============================================================

    'validation.post.title_required' => 'Title cannot be blank.',
    'validation.post.title_max_length' => 'Title cannot exceed {{ limit }} characters.',

    'validation.post.slug_required' => 'Slug is required.',
    'validation.post.slug_invalid' => 'Invalid slug.',
    'validation.post.slug_max_length' => 'Slug cannot exceed {{ limit }} characters.',

    'validation.post.status_invalid' => 'Invalid post status.',

    'validation.post.user_required' => 'Author is required.',

    // ============================================================
    // COMMENT ENTITY
    // ============================================================

    'validation.comment.author_required' => 'Author cannot be blank.',
    'validation.comment.author_max_length' => 'Author name cannot exceed {{ limit }} characters.',

    'validation.comment.content_required' => 'Content cannot be blank.',

    'validation.comment.email_invalid' => 'Field must be a valid email address.',

    'validation.comment.url_invalid' => 'URL is invalid.',

    'validation.comment.post_required' => 'Post is required.',

    'validation.comment.status_invalid' => 'Invalid comment status.',

];
