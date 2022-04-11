<?php

namespace Clue\React\EventSource;

class MessageEvent
{
    /**
     * @param string $data
     * @param string $lastEventId
     * @return self
     * @internal
     */
    public static function parse($data, $lastEventId)
    {
        $lines = preg_split(
            '/\r\n|\r(?!\n)|\n/S',
            $data
        );

        $data = '';
        $id = $lastEventId;
        $type = 'message';
        $retry = null;

        foreach ($lines as $line) {
            $name = strstr($line, ':', true);
            $value = substr(strstr($line, ':'), 1);
            if (isset($value[0]) && $value[0] === ' ') {
                $value = substr($value, 1);
            }
            if ($name === 'data') {
                $data .= $value . "\n";
            } elseif ($name === 'id') {
                $id = $value;
            } elseif ($name === 'event') {
                $type = $value;
            } elseif ($name === 'retry' && $value === (string)(int)$value && $value >= 0) {
                $retry = (int)$value;
            }
        }

        if (substr($data, -1) === "\n") {
            $data = substr($data, 0, -1);
        }

        return new self($data, $id, $type, $retry);
    }

    /**
     * @internal
     * @param string $data
     * @param string $lastEventId
     * @param string $type
     * @param ?int   $retry
     */
    private function __construct($data, $lastEventId, $type, $retry)
    {
        $this->data = $data;
        $this->lastEventId = $lastEventId;
        $this->type = $type;
        $this->retry = $retry;
    }

    /**
     * @var string
     * @readonly
     */
    public $data = '';

    /**
     * @var string
     * @readonly
     */
    public $lastEventId = '';

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
