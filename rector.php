<?php

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector;

// The lowest PHP the package supports: Rector must not rewrite into syntax 8.2 cannot run.
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withSkip([
        // Types a container closure's `$app` as `array` because it is read as `$app['config']`;
        // Laravel passes the Application (ArrayAccess), so the closure would throw a TypeError.
        StrictArrayParamDimFetchRector::class,
    ]);
