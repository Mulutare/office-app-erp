<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test') throw new RuntimeException('Synthetic isolated database required.');
$splitter=new App\Database\SqlStatementSplitter();
foreach([__DIR__.'/../database/seeds/*.sql',__DIR__.'/fixtures/*.sql'] as $pattern) {
    $files=glob($pattern); sort($files,SORT_STRING);
    foreach($files as $file) foreach($splitter->split(file_get_contents($file)) as $sql) db()->exec($sql);
}
echo "Synthetic reference data and accounts loaded.\n";
