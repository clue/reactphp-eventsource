<?php

namespace Clue\React\EventSource;

class MessageEvent
{
    /**
     * @param string $data
     * @param string $lastEventId
     * @param float  $retryTime   passed by reference, will be updated with `retry` field in seconds if valid
     * @return self
     * @internal
     */
    public static function parse($data, $lastEventId, &$retryTime = 0.0)
    {
        $lines = preg_split(
            '/\r\n|\r(?!\n)|\n/S',
            $data
        );

        $data = '';
        $id = $lastEventId;
        $type = 'message';

        foreach ($lines as $line) {
            $name = strstr($line, ':', true);
            $value = (string) substr(strstr($line, ':'), 1);
            if (isset($value[0]) && $value[0] === ' ') {
                $value = (string) substr($value, 1);
            }
            if ($name === 'data') {
                $data .= self::utf8($value) . "\n";
            } elseif ($name === 'id' && \strpos($value, "\x00") === false) {
                $id = self::utf8($value);
            } elseif ($name === 'event' && $value !== '') {
                $type = self::utf8($value);
            } elseif ($name === 'retry' && $value === (string)(int)$value && $value >= 0) {
                $retryTime = $value * 0.001;
            }
        }

        if (substr($data, -1) === "\n") {
            $data = substr($data, 0, -1);
        }

        /** @throws void because parameter values are validated above already */
        return new self($data, $id, $type);
    }

    /** @return string */
    private static function utf8($string)
    {
        return \htmlspecialchars_decode(\htmlspecialchars($string, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'utf-8'));
    }

    /** @return bool */
    private static function isUtf8($string)
    {
        return $string === self::utf8($string);
    }

    /**
     * Create a new `MessageEvent` instance.
     *
     * This is mostly used internally to represent each incoming message event
     * (see also [`message` event](#message-event)). Likewise, you can also use
     * this class in test cases to test how your application reacts to incoming
     * messages.
     *
     * The constructor validates and initializes all properties of this class.
     * It throws an `InvalidArgumentException` if any parameters are invalid.
     *
     * @param string $data message data (requires valid UTF-8 data, possibly multi-line)
     * @param string $lastEventId optional last event ID (defaults to empty string, requires valid UTF-8, no null bytes, single line)
     * @param string $type optional event type (defaults to "message", requires valid UTF-8, single line)
     * @throws \InvalidArgumentException if any parameters are invalid
     */
    final public function __construct($data, $lastEventId = '', $type = 'message')
    {
        if (!self::isUtf8($data)) {
            throw new \InvalidArgumentException('Invalid $data given, must be valid UTF-8 string');
        }
        if (!self::isUtf8($lastEventId) || \strpos($lastEventId, "\0") !== false || \strpos($lastEventId, "\r") !== false || \strpos($lastEventId, "\n") !== false) {
            throw new \InvalidArgumentException('Invalid $lastEventId given, must be valid UTF-8 string with no null bytes or newline characters');
        }
        if (!self::isUtf8($type) || $type === '' || \strpos($type, "\r") !== false || \strpos($type, "\n")) {
            throw new \InvalidArgumentException('Invalid $type given, must be valid UTF-8 string with no newline characters');
        }

        $this->data = \preg_replace("/\r\n?/", "\n", $data);
        $this->lastEventId = $lastEventId;
        $this->type = $type;
    }

    /**
     * @var string
     * @readonly
     */
    public $data = '';

    /**
     * @var string defaults to empty string
     * @readonly
     */
    public $lastEventId = '';

    /**
     * @var string defaults to "message"
     * @readonly
     */
    public $type = 'message';
}
