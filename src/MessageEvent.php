<?php

namespace Clue\React\EventSource;

class MessageEvent
{
    /**
     * @param string $data
     * @return self
     * @internal
     */
    public static function parse($data)
    {
        $message = new self();

        $lines = preg_split(
            '/\r\n|\r(?!\n)|\n/S',
            $data
        );
        foreach ($lines as $line) {
            $name = strstr($line, ':', true);
            $value = substr(strstr($line, ':'), 1);
            if (isset($value[0]) && $value[0] === ' ') {
                $value = substr($value, 1);
            }
            if ($name === 'data') {
                $message->data .= $value . "\n";
            } elseif ($name === 'id') {
                $message->lastEventId .= $value;
            } elseif ($name === 'event') {
                $message->type = $value;
            } elseif ($name === 'retry' && $value === (string)(int)$value && $value >= 0) {
                $message->retry = (int)$value;
            }
        }

        if (substr($message->data, -1) === "\n") {
            $message->data = substr($message->data, 0, -1);
        }

        return $message;
    }

    /**
     * @var string
     * @readonly
     */
    public $data = '';

    /**
     * @var ?string
     * @readonly
     */
    public $lastEventId = null;

    /**
     * @var string
     * @readonly
     */
    public $type = 'message';

    /**
     * @internal
     * @var ?int
     * @readonly
     */
    public $retry;
}
