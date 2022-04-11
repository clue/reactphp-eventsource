<?php

namespace Clue\React\EventSource;

use Evenement\EventEmitter;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

/**
 * The `EventSource` class is responsible for communication with the remote Server-Sent Events (SSE) endpoint.
 *
 * The `EventSource` object works very similar to the one found in common
 * web browsers. Unless otherwise noted, it follows the same semantics as defined
 * under https://html.spec.whatwg.org/multipage/server-sent-events.html
 *
 * Its constructor simply requires the URL to the remote Server-Sent Events (SSE) endpoint:
 *
 * ```php
 * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php');
 * ```
 *
 * This class takes an optional `LoopInterface|null $loop` parameter that can be used to
 * pass the event loop instance to use for this object. You can use a `null` value
 * here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
 * This value SHOULD NOT be given unless you're sure you want to explicitly use a
 * given event loop instance.
 *
 * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
 * proxy servers etc.), you can explicitly pass a custom instance of the
 * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
 * to the [`Browser`](https://github.com/reactphp/http#browser) instance
 * and pass it as an additional argument to the `EventSource` like this:
 *
 * ```php
 * $connector = new React\Socket\Connector(null, [
 *     'dns' => '127.0.0.1',
 *     'tcp' => [
 *         'bindto' => '192.168.10.1:0'
 *     ],
 *     'tls' => [
 *         'verify_peer' => false,
 *         'verify_peer_name' => false
 *     ]
 * ]);
 * $browser = new React\Http\Browser(null, $connector);
 *
 * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php', null, $browser);
 * ```
 */
class EventSource extends EventEmitter
{
    // ready state
    const CONNECTING = 0;
    const OPEN = 1;
    const CLOSED = 2;

    /**
     * @var int (read-only)
     * @see self::CONNECTING
     * @see self::OPEN
     * @see self::CLOSED
     * @psalm-readonly-allow-private-mutation
     */
    public $readyState = self::CLOSED;

    /**
     * @var string (read-only) URL
     * @readonly
     */
    public $url;

    /**
     * @var string last event ID received
     */
    private $lastEventId = '';

    /**
     * @var LoopInterface
     * @readonly
     */
    private $loop;

    /**
     * @var Browser
     * @readonly
     */
    private $browser;

    /**
     * @var ?\React\Promise\PromiseInterface
     */
    private $request;

    /**
     * @var ?\React\EventLoop\TimerInterface
     */
    private $timer;

    /**
     * @var float
     */
    private $reconnectTime = 3.0;

    /**
     * The `EventSource` class is responsible for communication with the remote Server-Sent Events (SSE) endpoint.
     *
     * The `EventSource` object works very similar to the one found in common
     * web browsers. Unless otherwise noted, it follows the same semantics as defined
     * under https://html.spec.whatwg.org/multipage/server-sent-events.html
     *
     * Its constructor simply requires the URL to the remote Server-Sent Events (SSE) endpoint:
     *
     * ```php
     * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php');
     * ```
     *
     * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
     * proxy servers etc.), you can explicitly pass a custom instance of the
     * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
     * to the [`Browser`](https://github.com/reactphp/http#browser) instance
     * and pass it as an additional argument to the `EventSource` like this:
     *
     * ```php
     * $connector = new React\Socket\Connector([
     *     'dns' => '127.0.0.1',
     *     'tcp' => [
     *         'bindto' => '192.168.10.1:0'
     *     ],
     *     'tls' => [
     *         'verify_peer' => false,
     *         'verify_peer_name' => false
     *     ]
     * ]);
     * $browser = new React\Http\Browser($connector);
     *
     * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php', $browser);
     * ```
     *
     * This class takes an optional `LoopInterface|null $loop` parameter that can be used to
     * pass the event loop instance to use for this object. You can use a `null` value
     * here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
     * This value SHOULD NOT be given unless you're sure you want to explicitly use a
     * given event loop instance.
     *
     * @param string         $url
     * @param ?Browser       $browser
     * @param ?LoopInterface $loop
     * @throws \InvalidArgumentException for invalid URL
     */
    public function __construct($url, Browser $browser = null, LoopInterface $loop = null)
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('http', 'https'))) {
            throw new \InvalidArgumentException();
        }

        $this->loop = $loop ?: Loop::get();
        if ($browser === null) {
            $browser = new Browser(null, $this->loop);
        }
        $this->browser = $browser->withRejectErrorResponse(false);
        $this->url = $url;

        $this->readyState = self::CONNECTING;
        $this->request();
    }

    private function request()
    {
        $headers = array(
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-cache'
        );
        if ($this->lastEventId !== '') {
            $headers['Last-Event-ID'] = $this->lastEventId;
        }

        $this->request = $this->browser->requestStreaming(
            'GET',
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

            // match `Content-Type: text/event-stream` (case insensitve and ignore additional parameters)
            if (!preg_match('/^text\/event-stream(?:$|;)/i', $response->getHeaderLine('Content-Type'))) {
                $this->readyState = self::CLOSED;
                $this->emit('error', array(new \UnexpectedValueException('Unexpected Content-Type')));
                $this->close();
                return;
            }

            $stream = $response->getBody();
            assert($stream instanceof ReadableStreamInterface);

            $buffer = '';
            $stream->on('data', function ($chunk) use (&$buffer, $stream) {
                $messageEvents = preg_split(
                    '/(?:\r\n|\r(?!\n)|\n){2}/S',
                    $buffer . $chunk
                );
                $buffer = array_pop($messageEvents);

                foreach ($messageEvents as $data) {
                    $message = MessageEvent::parse($data, $this->lastEventId);
                    $this->lastEventId = $message->lastEventId;

                    if ($message->retry !== null) {
                        $this->reconnectTime = $message->retry / 1000;
                    }

                    if ($message->data !== '') {
                        $this->emit($message->type, array($message));
                        if ($this->readyState === self::CLOSED) {
                            break;
                        }
                    }
                }
            });

            $stream->on('close', function () use (&$buffer) {
                $buffer = '';
                $this->request = null;
                if ($this->readyState === self::OPEN) {
                    $this->readyState = self::CONNECTING;

                    $this->emit('error', [new \RuntimeException('Stream closed, reconnecting in ' . $this->reconnectTime . ' seconds')]);
                    if ($this->readyState === self::CLOSED) {
                        return;
                    }

                    $this->timer = $this->loop->addTimer($this->reconnectTime, function () {
                        $this->timer = null;
                        $this->request();
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

            $this->emit('error', [$e]);
            if ($this->readyState === self::CLOSED) {
                return;
            }

            $this->timer = $this->loop->addTimer($this->reconnectTime, function () {
                $this->timer = null;
                $this->request();
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
