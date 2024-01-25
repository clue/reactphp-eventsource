# Changelog

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
