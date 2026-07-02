<?php

namespace Clue\React\EventSource;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/**
 * The `SseDecoder` reads from a plain byte stream and emits a `MessageEvent` for each Server-Sent Event (SSE).
 *
 * Unlike the `EventSource` class which implements the higher-level HTML5
 * EventSource API (HTTP `GET` request, automatic reconnection, `readyState`
 * and `Last-Event-ID` handling), this class only decodes the SSE wire protocol.
 * This makes it reusable for any readable stream of `text/event-stream` data,
 * such as a streaming HTTP response to a custom request (think LLM streaming
 * APIs using an HTTP `POST` request).
 */
class SseDecoder extends EventEmitter implements ReadableStreamInterface
{
    /** @var ReadableStreamInterface */
    private $input;

    /** @var string */
    private $buffer = '';

    /** @var bool */
    private $closed = false;

    /**
     * @var string (read-only) last event ID received from the `id` field or an empty string if not given
     * @psalm-readonly-allow-private-mutation
     */
    public $lastEventId = '';

    /**
     * @var ?float (read-only) reconnection time in seconds from the `retry` field or null if not given
     * @psalm-readonly-allow-private-mutation
     */
    public $lastRetryTime = null;

    /**
     * @param ReadableStreamInterface $input       readable byte stream emitting `text/event-stream` data
     * @param string                  $lastEventId optional last event ID to resume from a previous stream
     */
    public function __construct(ReadableStreamInterface $input, $lastEventId = '')
    {
        $this->input = $input;
        $this->lastEventId = $lastEventId;

        if (!$input->isReadable()) {
            $this->close();
            return;
        }

        $this->input->on('data', array($this, 'handleData'));
        $this->input->on('end', array($this, 'handleEnd'));
        $this->input->on('error', array($this, 'handleError'));
        $this->input->on('close', array($this, 'close'));
    }

    public function isReadable()
    {
        return !$this->closed;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        $this->input->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function pause()
    {
        $this->input->pause();
    }

    public function resume()
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    /** @internal */
    public function handleData($data)
    {
        if (!\is_string($data)) {
            $this->handleError(new \UnexpectedValueException('Expected stream to emit string, but got ' . \gettype($data)));
            return;
        }

        $this->buffer .= $data;

        // keep parsing while a complete event (terminated by a blank line) has been found
        while (\preg_match('/(?:\r\n|\r(?!\n)|\n){2}/S', $this->buffer, $match, \PREG_OFFSET_CAPTURE) === 1) {
            // read event up until blank line and remove from buffer (including trailing blank line)
            $event = (string) \substr($this->buffer, 0, $match[0][1]);
            $this->buffer = (string) \substr($this->buffer, $match[0][1] + \strlen($match[0][0]));

            // `id` and `retry` configure the event stream rather than a single message and may
            // arrive on events that are never dispatched, so they are assigned to this stream
            // before the `data` event is emitted and can be read at any time
            $message = MessageEvent::parse($event, $this->lastEventId, $this->lastRetryTime);
            $this->lastEventId = $message->lastEventId;

            // dispatch event unless its data buffer is empty (as per SSE specs)
            if ($message->data !== '') {
                $this->emit('data', array($message));

                if ($this->closed) {
                    return;
                }
            }
        }
    }

    /** @internal */
    public function handleEnd()
    {
        // discard any incomplete event left in the buffer (as per SSE specs)
        if (!$this->closed) {
            $this->emit('end');
            $this->close();
        }
    }

    /** @internal */
    public function handleError(\Exception $error)
    {
        $this->emit('error', array($error));
        $this->close();
    }
}
