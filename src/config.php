<?php
$configFile=is_file(dirname(__DIR__).'/.env')?dirname(__DIR__).'/.env':dirname(__DIR__).'/storage/site.env';
$values=is_file($configFile)?parse_ini_file($configFile,false,INI_SCANNER_RAW):[];
define('APP_URL',rtrim(getenv('TRIPOVELA_URL')?:($values['APP_URL']??'http://127.0.0.1:8787'),'/'));
define('APP_ENV',getenv('TRIPOVELA_ENV')?:($values['APP_ENV']??'local'));
$storagePath=getenv('TRIPOVELA_STORAGE')?:($values['STORAGE_PATH']??dirname(__DIR__).'/storage');
if(!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~',$storagePath))$storagePath=dirname(__DIR__).'/'.$storagePath;
define('LIVE_STORAGE_PATH',$storagePath);
require_once __DIR__.'/admin-testing.php';
define('STORAGE_PATH',qaBoot(LIVE_STORAGE_PATH));
define('ADMIN_SETUP_HASH',$values['ADMIN_SETUP_HASH']??'');
define('ADMIN_SETUP_EXPIRES',(int)($values['ADMIN_SETUP_EXPIRES']??0));
