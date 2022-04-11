<?php

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[1]) || isset($argv[2])) {
    exit('Usage error: stream.php <uri>' . PHP_EOL);
}

$es = new Clue\React\EventSource\EventSource($argv[1]);

$es->on('message', function (Clue\React\EventSource\MessageEvent $message) {
    // $json = json_decode($message->data);
    var_dump($message);
});

$es->on('open', function () {
    echo 'open' . PHP_EOL;
});

$es->on('error', function (Exception $e) use ($es) {
    if ($es->readyState === Clue\React\EventSource\EventSource::CLOSED) {
        echo 'Permanent error: ' . $e->getMessage() . PHP_EOL;
    } else {
        echo 'Temporary error: ' . $e->getMessage() . PHP_EOL;
    }
});
