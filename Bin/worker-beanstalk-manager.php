#!/usr/bin/env /opt/install/php/bin/php
<?php
namespace Bin;
use Yaf\Loader as InternalLoader;

declare(ticks = 1);
$loader = InternalLoader::getInstance(realpath(dirname(dirname(__FILE__))), ini_get('yaf.library'));
$loader->registerLocalNamespace(array('Bin'));
spl_autoload_register(array($loader, 'autoload'));

// load worker configure file for command line
$config_dir = "/data/web/jinritemai_com/Backend/Worker/Bin/beanstalk-config.ini";
$configArray = parse_ini_file($config_dir, true);
// todo  alternative loader beanstalkd-config.ini here
$mgr = new WorkerBeanstalkManager($loader);
$mgr->run();