<?php

namespace Clue\React\EventSource;

use React\EventLoop\LoopInterface;
use Psr\Http\Message\ResponseInterface;
use React\Stream\ReadableStreamInterface;
use Clue\React\Buzz\Browser;
use Evenement\EventEmitter;
use React\Socket\ConnectorInterface;

class EventSource extends EventEmitter
{
    /**
     * @var string (read-only) last event ID received
     */
    public $lastEventId = '';

    // ready state
    const CONNECTING = 0;
    const OPEN = 1;
    const CLOSED = 2;

    /**
     * @var int (read-only)
     * @see self::CONNECTING
     * @see self::OPEN
     * @see self::CLOSED
     */
    public $readyState = self::CLOSED;

    /**
     * @var string (read-only) URL
     */
    public $url;

    private $loop;
    private $browser;
    private $request;
    private $timer;
    private $reconnectTime = 3.0;

    public function __construct($url, LoopInterface $loop, ConnectorInterface $connector = null)
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('http', 'https'))) {
            throw new \InvalidArgumentException();
        }

        $browser = new Browser($loop, $connector);
        $this->browser = $browser->withOptions(array('streaming' => true, 'obeySuccessCode' => false));
        $this->loop = $loop;
        $this->url = $url;

        $this->readyState = self::CONNECTING;

        $this->timer = $loop->addTimer(0, function () {
            $this->timer = null;
            $this->send();
        });
    }

    private function send()
    {
        $headers = array(
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-cache'
        );
        if ($this->lastEventId !== '') {
            $headers['Last-Event-ID'] = $this->lastEventId;
        }

        $this->request = $this->browser->get(
            $this->url,
            $headers
        );
        $this->request->then(function (ResponseInterface $response) {
            if ($response->getStatusCode() !== 200) {
                $this->readyState = self::CLOSED;
                $this->emit('error', array(new \UnexpectedValueException('Unexpected status code')));
                $this->close();
                return;
            }

            if ($response->getHeaderLine('Content-Type') !== 'text/event-stream') {
                $this->readyState = self::CLOSED;
                $this->emit('error', array(new \UnexpectedValueException('Unexpected Content-Type')));
                $this->close();
                return;
            }

            $stream = $response->getBody();
            assert($stream instanceof ReadableStreamInterface);

            $buffer = '';
            $stream->on('data', function ($chunk) use (&$buffer, $stream) {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $data = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $message = MessageEvent::parse($data);
                    if ($message->lastEventId === null) {
                        $message->lastEventId = $this->lastEventId;
                    } else {
                        $this->lastEventId = $message->lastEventId;
                    }

                    if ($message->data !== '') {
                        $this->emit($message->type, array($message));
                    }
                }
            });

            $stream->on('close', function () {
                $this->request = null;
                if ($this->readyState === self::OPEN) {
                    $this->readyState = self::CONNECTING;
                    $this->timer = $this->loop->addTimer($this->reconnectTime, function () {
                        $this->timer = null;
                        $this->send();
                    });
                }
            });

            $this->readyState = self::OPEN;
            $this->emit('open');
        })->then(null, function ($e) {
            $this->request = null;
            if ($this->readyState === self::CLOSED) {
                return;
            }

            $this->emit('error', array($e));
            if ($this->readyState === self::CLOSED) {
                return;
            }

            $this->timer = $this->loop->addTimer($this->reconnectTime, function () {
                $this->timer = null;
                $this->send();
            });
        });
    }

    public function close()
    {
        $this->readyState = self::CLOSED;
        if ($this->request !== null) {
            $request = $this->request;
            $this->request = null;

            $request->then(function (ResponseInterface $response) {
                $response->getBody()->close();
            });
            $request->cancel();
        }

        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->removeAllListeners();
    }
}
