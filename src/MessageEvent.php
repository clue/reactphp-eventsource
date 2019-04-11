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
        $message = new MessageEvent();

        preg_match_all('/^([a-z]*)\: ?(.*)/m', $data, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match[1] === 'data') {
                $message->data .= $match[2] . "\n";
            } elseif ($match[1] === 'id') {
                $message->lastEventId .= $match[2];
            } elseif ($match[1] === 'event') {
                $message->type = $match[2];
            }
        }

        if (substr($message->data, -1) === "\n") {
            $message->data = substr($message->data, 0, -1);
        }
        //$message->data = rtrim($message->data, "\r\n");

        return $message;
    }

    /**
     * @var string
     */
    public $data = '';

    /**
     * @var ?string
     */
    public $lastEventId = null;

    /**
     * @var string
     */
    public $type = 'message';
}
