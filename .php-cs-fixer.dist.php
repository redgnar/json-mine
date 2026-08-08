<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in([__DIR__ . '/benchmarks', __DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__]);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setCacheFile('.cache/php-cs-fixer.cache')
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => ['import_classes' => false, 'import_functions' => false, 'import_constants' => false],
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'phpdoc_align' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder);
