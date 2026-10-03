<?php
declare(strict_types=1);
// Shared, dependency-free contract files are bundled into lib/ by tools/package-careers.ps1.
foreach(['Rules','CareersContract','CareersRequestSigner'] as $class) {
    $path=dirname(__DIR__).'/lib/'.$class.'.php';
    if(!is_file($path)) $path=dirname(__DIR__,2).'/app/services/Recruitment/'.$class.'.php';
    require_once $path;
}
require_once __DIR__.'/Portal.php';
