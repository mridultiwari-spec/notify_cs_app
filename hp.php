<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$log = "This is test log";

file_put_contents('/var/www/html/notifycsapp/file/debug_log.txt', $log);
