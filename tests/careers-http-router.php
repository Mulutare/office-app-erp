<?php
declare(strict_types=1);
// Test-only TLS termination adapter. Never included in either deployable package.
if(getenv('APP_ENV')!=='testing') {http_response_code(404);exit;}
$_SERVER['HTTPS']='on';
if(getenv('CAREERS_PREVIEW')==='1') $_SERVER['HTTP_HOST']=parse_url((string)getenv('CAREERS_BASE_URL'),PHP_URL_HOST);
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/careers/careers.css') {header('Content-Type: text/css');readfile(__DIR__.'/../careers/public/careers.css');exit;}
require __DIR__.'/../careers/public/index.php';
