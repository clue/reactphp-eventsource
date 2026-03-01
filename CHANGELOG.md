# Changelog

## 1.4.0 (2026-03-01)

*   Feature: Improve error reporting for invalid responses by using `ResponseException`.
    (#51 by @clue)

*   Feature: Improve PHP 8.5+ support and update test environment.
    (#50 by @clue)

*   Improve test suite to support legacy PHP 7.2 with PHPUnit 8.5.
    (#49 by @clue)

## 1.3.0 (2025-04-11)

Today’s release is extra special because it’s also my birthday! 🎉
A huge thank you to all sponsors for your continued support, it means so much.
If you’re thinking about sponsoring, now’s a great time to [join the fun](https://github.com/sponsors/clue)! ❤️

*   Feature: Improve PHP 8.4+ support by avoiding implicitly nullable types.
    (#46 and #47 by @clue)

*   Improve test suite, update to reactphp/http `v1.10.0` and avoid using internal classes.
    (#45 by @SimonFrings and #48 by @clue)

## 1.2.0 (2024-01-25)

*   Feature / Fix: Forward compatibility with Promise v3.
    (#42 by @clue)

*   Feature: Full PHP 8.3 compatibility.
    (#44 by @yadaiio)

*   Minor documentation improvements.
    (#43 by @yadaiio)

## 1.1.0 (2023-04-11)

*   Feature: Public `MessageEvent` constructor and refactor property assignments.
    (#36 and #41 by @clue)

    This is mostly used internally to represent each incoming message event
    (see also `message` event). Likewise, you can also use this class in test
    cases to test how your application reacts to incoming messages.

    ```php
    $message = new Clue\React\EventSource\MessageEvent('hello', '42', 'message');

    assert($message->data === 'hello');
    assert($message->lastEventId === '42');
    assert($message->type === 'message');
    ```

*   Feature / Fix: Use replacement character for invalid UTF-8, handle null bytes and ignore empty `event` type as per EventSource specs.
    (#33 and #40 by @clue)

*   Feature: Full support for PHP 8.2 and update test environment.
    (#38 by @clue)

*   Improve test suite, ensure 100% code coverage and report failed assertions.
    (#35 by @clue and #39 by @clue)

## 1.0.0 (2022-04-11)

*   First stable release, now following SemVer! 🎉
    Thanks to all the supporters and contributors who helped shape the project!

## 0.0.0 (2019-04-11)

*   Initial import
