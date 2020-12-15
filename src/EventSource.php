<?php

namespace Clue\React\EventSource;

use Evenement\EventEmitter;
use Psr\Http\Message\ResponseInterface;
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
 * It requires the URL to the remote Server-Sent Events (SSE) endpoint and also
 * registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage)
 * in order to handle async HTTP requests.
 *
 * ```php
 * $loop = React\EventLoop\Factory::create();
 *
 * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php', $loop);
 * ```
 *
 * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
 * proxy servers etc.), you can explicitly pass a custom instance of the
 * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
 * to the [`Browser`](https://github.com/reactphp/http#browser) instance
 * and pass it as an additional argument to the `EventSource` like this:
 *
 * ```php
 * $connector = new React\Socket\Connector($loop, array(
 *     'dns' => '127.0.0.1',
 *     'tcp' => array(
 *         'bindto' => '192.168.10.1:0'
 *     ),
 *     'tls' => array(
 *         'verify_peer' => false,
 *         'verify_peer_name' => false
 *     )
 * ));
 * $browser = new React\Http\Browser($loop, $connector);
 *
 * $es = new Clue\React\EventSource\EventSource('https://example.com/stream.php', $loop, $browser);
 * ```
 */
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
    private $headers;
    private $defaultHeaders = [
        'Accept' => 'text/event-stream',
        'Cache-Control' => 'no-cache'
    ];

    public function __construct($url, LoopInterface $loop, Browser $browser = null, array $headers = [])
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('http', 'https'))) {
            throw new \InvalidArgumentException();
        }

        if ($browser === null) {
            $browser = new Browser($loop);
        }
        $this->browser = $browser->withRejectErrorResponse(false);
        $this->loop = $loop;
        $this->url = $url;

        $this->headers = $this->mergeHeaders($headers);

        $this->readyState = self::CONNECTING;
        $this->request();
    }

    private function mergeHeaders(array $headers = [])
    {
        if ($headers === []) {
            return $this->defaultHeaders;
        }

        // HTTP headers are case insensitive, we do not want to have different cases for the same (default) header
        // Convert default headers to lowercase, to ease the custom headers potential override comparison
        $loweredDefaults = array_change_key_case($this->defaultHeaders, CASE_LOWER);
        foreach($headers as $k => $v) {
            if (array_key_exists(strtolower($k), $loweredDefaults)) {
                unset($headers[$k]);
            }
        }
        return array_merge(
            $headers,
            $this->defaultHeaders
        );
    }

    private function request()
    {
        $headers = $this->headers;
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
