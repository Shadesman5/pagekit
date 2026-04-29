<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
        'app/vendor',
        'app/assets',
        'node_modules',
        'docker',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    // Match only root-level runtime config.php (gitignored), NOT tracked
    // app/system/config.php or app/installer/config.php. The anchored regex
    // is required: notPath('config.php') would be treated as a substring
    // match by Symfony Finder and silently exclude the tracked files too.
    ->notPath('/^config\.php$/');

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => false,
        'trailing_comma_in_multiline' => true,
        'phpdoc_scalar' => true,
        'unary_operator_spaces' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_var_without_name' => true,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        // Moderate Modernisierungen (nicht zu aggressiv)
        'modernize_types_casting' => true,
        'no_unneeded_control_parentheses' => true,
        // TODO: Must be refactored in Step 2.1.3 (strict_types Migration)
    ])
    ->setFinder($finder);
