<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'node_modules', 'public/build', 'extension'])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
    ])
    ->setFinder($finder)
;
