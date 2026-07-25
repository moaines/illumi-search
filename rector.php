<?php

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;

return RectorConfig::configure()
    ->withPhpSets()
    ->withRules([
        AnnotationToAttributeRector::class,
    ])
    ->withConfiguredRule(
        AnnotationToAttributeRector::class,
        [
            new AnnotationToAttribute('test'),
            new AnnotationToAttribute('dataProvider'),
            new AnnotationToAttribute('depends'),
            new AnnotationToAttribute('group'),
            new AnnotationToAttribute('requires'),
        ],
    )
    ->withPaths([
        __DIR__ . '/tests',
    ]);
