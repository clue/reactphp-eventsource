<?php

use React\EventLoop\Factory;
use Clue\React\EventSource\EventSource;

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[1]) || isset($argv[2])) {
    exit('Usage error: stream.php <uri>' . PHP_EOL);
}

$loop = Factory::create();
$es = new EventSource($argv[1], $loop);

$es->on('message', function ($message) {
    //$data = json_decode($message->data);
    var_dump($message);
});

$es->on('error', function (Exception $e) use ($es) {
    if ($es->readyState === EventSource::CLOSED) {
        echo 'Permanent error: ' . $e->getMessage() . PHP_EOL;
    } else {
        echo 'Temporary error: ' . $e->getMessage() . PHP_EOL;
    }
});

$loop->run();
