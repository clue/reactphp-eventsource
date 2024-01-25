# clue/reactphp-eventsource

[![CI status](https://github.com/clue/reactphp-eventsource/actions/workflows/ci.yml/badge.svg)](https://github.com/clue/reactphp-eventsource/actions)
[![code coverage](https://img.shields.io/badge/code%20coverage-100%25-success)](#tests)
[![installs on Packagist](https://img.shields.io/packagist/dt/clue/reactphp-eventsource?color=blue&label=installs%20on%20Packagist)](https://packagist.org/packages/clue/reactphp-eventsource)

Instant real-time updates. Lightweight EventSource client receiving live
messages via HTML5 Server-Sent Events (SSE). Fast stream processing built on top
of [ReactPHP](https://reactphp.org/)'s event-driven architecture.

**Table of contents**

* [Support us](#support-us)
* [Quickstart example](#quickstart-example)
* [Usage](#usage)
    * [EventSource](#eventsource)
        * [message event](#message-event)
        * [open event](#open-event)
        * [error event](#error-event)
        * [EventSource::$readyState](#eventsourcereadystate)
        * [EventSource::$url](#eventsourceurl)
        * [close()](#close)
    * [MessageEvent](#messageevent)
        * [MessageEvent::__construct()](#messageevent__construct)
        * [MessageEvent::$data](#messageeventdata)
        * [MessageEvent::$lastEventId](#messageeventlasteventid)
        * [MessageEvent::$type](#messageeventtype)
* [Install](#install)
* [Tests](#tests)
* [License](#license)
* [More](#more)

## Support us

We invest a lot of time developing, maintaining and updating our awesome
open-source projects. You can help us sustain this high-quality of our work by
[becoming a sponsor on GitHub](https://github.com/sponsors/clue). Sponsors get
numerous benefits in return, see our [sponsoring page](https://github.com/sponsors/clue)
for details.

Let's take these projects to the next level together! 🚀

## Quickstart example

Once [installed](#install), you can use the following code to stream messages
from any Server-Sent Events (SSE) server endpoint:

```
data: {"name":"Alice","message":"Hello everybody!"}

data: {"name":"Bob","message":"Hey Alice!"}

data: {"name":"Carol","message":"Nice to see you Alice!"}

data: {"name":"Alice","message":"What a lovely chat!"}

data: {"name":"Bob","message":"All powered by ReactPHP, such an awesome piece of technology :)"}
```

```php
$es = new Clue\React\EventSource\EventSource('https://example.com/stream.php');

$es->on('message', function (Clue\React\EventSource\MessageEvent $message) {
    $json = json_decode($message->data);
    echo $json->name . ': ' . $json->message . PHP_EOL;
});
```

See the [examples](examples/).

## Usage

### EventSource

The `EventSource` class is responsible for communication with the remote Server-Sent Events (SSE) endpoint.

The `EventSource` object works very similar to the one found in common
web browsers. Unless otherwise noted, it follows the same semantics as defined
under https://html.spec.whatwg.org/multipage/server-sent-events.html

Its constructor simply requires the URL to the remote Server-Sent Events (SSE) endpoint:

```php
$es = new Clue\React\EventSource\EventSource('https://example.com/stream.php');
```

If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
proxy servers etc.), you can explicitly pass a custom instance of the
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
to the [`Browser`](https://github.com/reactphp/http#browser) instance
and pass it as an additional argument to the `EventSource` like this:

```php
$connector = new React\Socket\Connector([
    'dns' => '127.0.0.1',
    'tcp' => [
        'bindto' => '192.168.10.1:0'
    ],
    'tls' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);
$browser = new React\Http\Browser($connector);

$es = new Clue\React\EventSource\EventSource('https://example.com/stream.php', $browser);
```

This class takes an optional `LoopInterface|null $loop` parameter that can be used to
pass the event loop instance to use for this object. You can use a `null` value
here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
This value SHOULD NOT be given unless you're sure you want to explicitly use a
given event loop instance.

#### message event

The `message` event will be emitted whenever an EventSource message is received.

```php
$es->on('message', function (Clue\React\EventSource\MessageEvent $message) {
    // $json = json_decode($message->data);
    var_dump($message);
});
```

The EventSource stream may emit any number of messages over its lifetime. Each
`message` event will receive a [`MessageEvent` object](#messageevent).

The [`MessageEvent::$data` property](#messageeventdata) can be used to access
the message payload data. It is commonly used for transporting structured data
such as JSON:

```
data: {"name":"Alice","age":30}

data: {"name":"Bob","age":50}
```
```php
$es->on('message', function (Clue\React\EventSource\MessageEvent $message) {
    $json = json_decode($message->data);
    echo "{$json->name} is {$json->age} years old" . PHP_EOL;
});
```

The EventSource stream may specify an event type for each incoming message. This
`event` field can be used to emit appropriate event types like this:

```
data: Alice
event: join

data: Hello!
event: chat

data: Bob
event: leave
```
```php
$es->on('join', function (Clue\React\EventSource\MessageEvent $message) {
    echo $message->data . ' joined' . PHP_EOL;
});

$es->on('chat', function (Clue\React\EventSource\MessageEvent $message) {
    echo 'Message: ' . $message->data . PHP_EOL;
});

$es->on('leave', function (Clue\React\EventSource\MessageEvent $message) {
    echo $message->data . ' left' . PHP_EOL;
});
```

See also [`MessageEvent::$type` property](#messageeventtype) for more details.

#### open event

The `open` event will be emitted when the EventSource connection is successfully established.

```php
$es->on('open', function () {
    echo 'Connection opened' . PHP_EOL;
});
```

Once the EventSource connection is open, it may emit any number of
[`message` events](#message-event).

If the connection can not be opened successfully, it will emit an
[`error` event](#error-event) instead.

#### error event

The `error` event will be emitted when the EventSource connection fails.
The event receives a single `Exception` argument for the error instance.

```php
$redis->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

The EventSource connection will be retried automatically when it is temporarily
disconnected. If the server sends a non-successful HTTP status code or an
invalid `Content-Type` response header, the connection will fail permanently.

```php
$es->on('error', function (Exception $e) use ($es) {
    if ($es->readyState === Clue\React\EventSource\EventSource::CLOSED) {
        echo 'Permanent error: ' . $e->getMessage() . PHP_EOL;
    } else {
        echo 'Temporary error: ' . $e->getMessage() . PHP_EOL;
    }
});
```

See also the [`EventSource::$readyState` property](#eventsourcereadystate).

#### EventSource::$readyState

The `int $readyState` property can be used to
check the current EventSource connection state.

The state is read-only and can be in one of three states over its lifetime:

* `EventSource::CONNECTING`
* `EventSource::OPEN`
* `EventSource::CLOSED`

#### EventSource::$url

The `readonly string $url` property can be used to
get the EventSource URL as given to the constructor.

#### close()

The `close(): void` method can be used to
forcefully close the EventSource connection.

This will close any active connections or connection attempts and go into the
`EventSource::CLOSED` state.

### MessageEvent

The `MessageEvent` class represents an incoming EventSource message.

#### MessageEvent::__construct()

The `new MessageEvent(string $data, string $lastEventId = '', string $type = 'message')` constructor can be used to
create a new `MessageEvent` instance.

This is mostly used internally to represent each incoming message event
(see also [`message` event](#message-event)). Likewise, you can also use
this class in test cases to test how your application reacts to incoming
messages.

The constructor validates and initializes all properties of this class.
It throws an `InvalidArgumentException` if any parameters are invalid.

#### MessageEvent::$data

The `readonly string $data` property can be used to
access the message payload data.

```
data: hello
```
```php
assert($message->data === 'hello');
```

The `data` field may also span multiple lines. This is commonly used for
transporting structured data such as JSON:

```
data: {
data:     "message": "hello"
data: }
```
```php
$json = json_decode($message->data);
assert($json->message === 'hello');
```

If the message does not contain a `data` field or the `data` field is empty, the
message will be discarded without emitting an event.

#### MessageEvent::$lastEventId

The `readonly string $lastEventId` property can be used to
access the last event ID.

```
data: hello
id: 1
```
```php
assert($message->data === 'hello');
assert($message->lastEventId === '1');
```

Internally, the `id` field will automatically be used as the `Last-Event-ID` HTTP
request header in case the connection is interrupted.

If the message does not contain an `id` field, the `$lastEventId` property will
be the value of the last ID received. If no previous message contained an ID, it
will default to an empty string.

#### MessageEvent::$type

The `readonly string $type` property can be used to
access the message event type.

```
data: Alice
event: join
```
```php
assert($message->data === 'Alice');
assert($message->type === 'join');
```

Internally, the `event` field will be used to emit the appropriate event type.
See also [`message` event](#message-event).

If the message does not contain a `event` field or the `event` field is empty,
the `$type` property will default to `message`.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org/).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This project follows [SemVer](https://semver.org/).
This will install the latest supported version:

```bash
composer require clue/reactphp-eventsource:^1.2
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.4 through current PHP 8+.
It's *highly recommended to use the latest supported PHP version* for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org/):

```bash
composer install
```

To run the test suite, go to the project root and run:

```bash
vendor/bin/phpunit
```

The test suite is set up to always ensure 100% code coverage across all
supported environments. If you have the Xdebug extension installed, you can also
generate a code coverage report locally like this:

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

## License

This project is released under the permissive [MIT license](LICENSE).

> Did you know that I offer custom development services and issuing invoices for
  sponsorships of releases and for contributions? Contact me (@clue) for details.

## More

* If you want to learn more about processing streams of data, refer to the documentation of
  the underlying [react/stream](https://github.com/reactphp/stream) component.

* If you're looking to run the server side of your Server-Sent Events (SSE)
  application, you may want to use the powerful server implementation provided
  by [Framework X](https://framework-x.org/).
