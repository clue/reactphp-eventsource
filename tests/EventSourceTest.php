<?php

namespace Clue\Tests\React\EventSource;

use Clue\React\EventSource\EventSource;
use PHPUnit\Framework\TestCase;
use React\Http\Io\ReadableBodyStream;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Stream\ThroughStream;
use RingCentral\Psr7\Response;

class EventSourceTest extends TestCase
{
    public function testConstructorThrowsIfFirstArgumentIsNotAnUri()
    {
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('///');
    }

    public function testConstructorThrowsIfUriArgumentDoesNotIncludeScheme()
    {
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('example.com');
    }

    public function testConstructorThrowsIfUriArgumentIncludesInvalidScheme()
    {
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('ftp://example.com');
    }

    public function testConstructWithoutBrowserAndLoopAssignsBrowserAndLoopAutomatically()
    {
        $es = new EventSource('http://example.com');

        $ref = new \ReflectionProperty($es, 'browser');
        $ref->setAccessible(true);
        $browser = $ref->getValue($es);

        $this->assertInstanceOf('React\Http\Browser', $browser);

        $ref = new \ReflectionProperty($es, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($es);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);

        $es->close();
    }

    public function testConstructorWillSendGetRequestThroughGivenBrowser()
    {
        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->with(false)->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->with('GET', 'http://example.com')->willReturn($pending);

        new EventSource('http://example.com', $browser);
    }

    public function testConstructorWillSendGetRequestThroughGivenBrowserWithHttpsScheme()
    {
        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->with(false)->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->with('GET', 'https://example.com')->willReturn($pending);

        new EventSource('https://example.com', $browser);
    }

    public function testCloseWillCancelPendingGetRequest()
    {
        $cancelled = null;
        $pending = new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
        });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($pending);

        $es = new EventSource('http://example.com', $browser);
        $es->close();

        $this->assertEquals(1, $cancelled);
    }

    public function testCloseWillNotEmitErrorEventWhenGetRequestCancellationHandlerRejects()
    {
        $pending = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($pending);

        $es = new EventSource('http://example.com', $browser);

        $error = null;
        $es->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $es->close();

        $this->assertNull($error);
    }

    public function testConstructorWillStartGetRequestThatWillStartRetryTimerWhenGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->isType('callable')
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        new EventSource('http://example.com', $browser, $loop);

        $deferred->reject(new \RuntimeException());
    }

    public function testConstructorWillStartGetRequestThatWillStartRetryTimerThatWillRetryGetRequestWhenInitialGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerRetry = null;
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->callback(function ($cb) use (&$timerRetry) {
                $timerRetry = $cb;
                return true;
            })
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('requestStreaming')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        new EventSource('http://example.com', $browser, $loop);

        $deferred->reject(new \RuntimeException());

        $this->assertNotNull($timerRetry);
        $timerRetry();
    }

    public function testConstructorWillStartGetRequestThatWillEmitErrorWhenGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->isType('callable')
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser, $loop);

        $caught = null;
        $es->on('error', function ($e) use (&$caught) {
            $caught = $e;
        });
        $deferred->reject($expected = new \RuntimeException());

        $this->assertSame($expected, $caught);
    }

    public function testConstructorWillStartGetRequestThatWillNotStartRetryTimerWhenGetRequestRejectAndErrorHandlerClosesExplicitly()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $es->on('error', function () use ($es) {
            $es->close();
        });
        $deferred->reject(new \RuntimeException());
    }

    public function testCloseAfterGetRequestFromConstructorFailsWillCancelPendingRetryTimer()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->isType('callable')
        )->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser, $loop);

        $deferred->reject(new \RuntimeException());

        $es->close();
    }

    public function testConstructorWillReportFatalErrorWhenGetResponseResolvesWithInvalidStatusCode()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $readyState = null;
        $caught = null;
        $es->on('error', function ($e) use ($es, &$readyState, &$caught) {
            $readyState = $es->readyState;
            $caught = $e;
        });

        $response = new Response(400, array('Content-Type' => 'text/event-stream'), '');
        $deferred->resolve($response);

        $this->assertEquals(EventSource::CLOSED, $readyState);
        $this->assertInstanceOf('UnexpectedValueException', $caught);
    }

    public function testConstructorWillReportFatalErrorWhenGetResponseResolvesWithInvalidContentType()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $readyState = null;
        $caught = null;
        $es->on('error', function ($e) use ($es, &$readyState, &$caught) {
            $readyState = $es->readyState;
            $caught = $e;
        });

        $response = new Response(200, array(), '');
        $deferred->resolve($response);

        $this->assertEquals(EventSource::CLOSED, $readyState);
        $this->assertInstanceOf('UnexpectedValueException', $caught);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponse()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponseWithCaseInsensitiveContentType()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $stream = new ThroughStream();
        $response = new Response(200, array('CONTENT-type' => 'TEXT/Event-Stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponseAndSuperfluousParametersAfterTimer()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream;charset=utf-8;foo=bar'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testCloseResponseStreamWillStartRetryTimerWithErrorEvent()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->isType('callable')
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser, $loop);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $error = null;
        $es->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $stream->close();

        $this->assertEquals(EventSource::CONNECTING, $es->readyState);
        $this->assertInstanceOf('RuntimeException', $error);
        $this->assertEquals('Stream closed, reconnecting in 3 seconds', $error->getMessage());
    }

    public function testCloseResponseStreamWillNotStartRetryTimerWhenEventSourceIsClosedFromErrorHandler()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser, $loop);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $es->on('error', function ($e) use ($es) {
            $es->close();
        });

        $stream->close();

        $this->assertEquals(EventSource::CLOSED, $es->readyState);
    }

    public function testCloseFromOpenEventWillCloseResponseStreamAndCloseEventSource()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $es->on('open', function () use ($es) {
            $es->close();
        });

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $this->assertEquals(EventSource::CLOSED, $es->readyState);
        $this->assertFalse($stream->isReadable());
    }

    public function testEmitMessageWithParsedDataFromEventStream()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("data: hello\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello', $message->data);
        $this->assertEquals('', $message->lastEventId);
    }

    public function testEmitMessageWithParsedIdAndDataOverMultipleRowsFromEventStream()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id: 100\ndata: hello\ndata: world\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello' . "\n" . 'world', $message->data);
        $this->assertEquals('100', $message->lastEventId);
    }

    public function testEmitMessageWithParsedEventTypeAndDataWithTrailingWhitespaceFromEventStream()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('patch', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("event:patch\ndata:[]\ndata:\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('[]' . "\n", $message->data);
    }

    public function testDoesNotEmitMessageWhenParsedEventStreamHasNoData()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id:123\n\n");

        $this->assertNull($message);
    }

    public function testEmitMessageWithParsedDataAndPreviousIdWhenNotGivenAgainFromEventStream()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id:123\n\ndata:hi\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hi', $message->data);
        $this->assertEquals('123', $message->lastEventId);
    }

    public function testEmitMessageOnceWhenCallingCloseFromMessageHandlerFromEventStreamWithMultipleMessages()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $message = null;
        $es->on('message', function ($m) use (&$message, $es) {
            $message = $m;
            $es->close();
        });

        $stream->write("id:1\ndata:hello\n\nid:2\ndata:world\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('1', $message->lastEventId);
        $this->assertEquals('1', $es->lastEventId);
    }

    public function testReconnectAfterStreamClosesUsesLastEventIdFromParsedEventStreamForNextRequest()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerReconnect = null;
        $loop->expects($this->once())->method('addTimer')->with(
            3.0,
            $this->callback(function ($cb) use (&$timerReconnect) {
                $timerReconnect = $cb;
                return true;
            })
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('requestStreaming')->withConsecutive(
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']],
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Last-Event-ID' => '123']]
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $es = new EventSource('http://example.com', $browser, $loop);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $stream->write("id:123\n\n");
        $stream->end();

        $this->assertNotNull($timerReconnect);
        $timerReconnect();
    }

    public function testReconnectAfterStreamClosesUsesSpecifiedRetryTime()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerReconnect = null;
        $loop->expects($this->once())->method('addTimer')->with(
            2.543,
            $this->callback(function ($cb) use (&$timerReconnect) {
                $timerReconnect = $cb;
                return true;
            })
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('requestStreaming')->withConsecutive(
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']],
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']]
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $es = new EventSource('http://example.com', $browser, $loop);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $stream->write("retry:2543\n\n");
        $stream->end();

        $this->assertNotNull($timerReconnect);
        $timerReconnect();
    }

    public function testReconnectAfterStreamClosesIgnoresInvalidRetryTime()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerReconnect = null;
        $loop->expects($this->once())->method('addTimer')->with(
            3,
            $this->callback(function ($cb) use (&$timerReconnect) {
                $timerReconnect = $cb;
                return true;
            })
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('requestStreaming')->withConsecutive(
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']],
            ['GET', 'http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']]
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $es = new EventSource('http://example.com', $browser, $loop);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $stream->write("retry:now\n\n");
        $stream->end();

        $this->assertNotNull($timerReconnect);
        $timerReconnect();
    }

    public function setExpectedException($exception, $exceptionMessage = '', $exceptionCode = null)
    {
        if (method_exists($this, 'expectException')) {
            // PHPUnit 5.2+
            $this->expectException($exception);
            if ($exceptionMessage !== '') {
                $this->expectExceptionMessage($exceptionMessage);
            }
            if ($exceptionCode !== null) {
                $this->expectExceptionCode($exceptionCode);
            }
        } else {
            // legacy PHPUnit 4 - PHPUnit 5.1
            parent::setExpectedException($exception, $exceptionMessage, $exceptionCode);
        }
    }

    public function testSplitMessagesWithCarriageReturn()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $messages = [];
        $es->on('message', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $stream->write("data:hello\r\rdata:hi\r\r");

        $expected = ['hello', 'hi'];
        $this->assertCount(count($expected), $messages);
        foreach ($messages as $i => $message) {
            $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
            $this->assertEquals($expected[$i], $message->data);
        }
    }

    public function testSplitMessagesWithWindowsEndOfLineSequence()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $messages = [];
        $es->on('message', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $stream->write("data:hello\r\n\r\ndata:hi\r\n\r\n");

        $expected = ['hello', 'hi'];
        $this->assertCount(count($expected), $messages);
        foreach ($messages as $i => $message) {
            $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
            $this->assertEquals($expected[$i], $message->data);
        }
    }

    public function testSplitMessagesWithBufferedWindowsEndOfLineSequence()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $messages = [];
        $es->on('message', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $stream->write("data:hello\r\n\r");
        $stream->write("\ndata:hi\r\n\r\n");

        $expected = ['hello', 'hi'];
        $this->assertCount(count($expected), $messages);
        foreach ($messages as $i => $message) {
            $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
            $this->assertEquals($expected[$i], $message->data);
        }
    }

    public function testSplitMessagesWithMixedEndOfLine()
    {
        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $messages = [];
        $es->on('message', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $stream->write("data: LF CR\n\rdata: CRLF LF\r\n\ndata: CRLF CR\r\n\rdata: LF CRLF\n\r\ndata: CR CRLF\r\r\n");

        $expected = ['LF CR', 'CRLF LF', 'CRLF CR', 'LF CRLF', 'CR CRLF'];
        $this->assertCount(count($expected), $messages);
        foreach ($messages as $i => $message) {
            $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
            $this->assertEquals($expected[$i], $message->data);
        }
    }
}
