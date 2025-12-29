<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests')
    ->notPath('vendor');

$config = new PhpCsFixer\Config();

return $config
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'function_typehint_space' => true,
        'include' => true,
        'lowercase_cast' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unused_imports' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'return_type_declaration' => ['space_before' => 'none'],
        'single_blank_line_before_namespace' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'trim_array_spaces' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
