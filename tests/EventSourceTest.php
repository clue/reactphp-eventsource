<?php

namespace Clue\Tests\React\EventSource;

use Clue\React\EventSource\EventSource;
use PHPUnit\Framework\TestCase;
use React\Promise\Promise;
use React\Promise\Deferred;
use React\Http\Io\ReadableBodyStream;
use React\Stream\ThroughStream;
use ReflectionProperty;
use RingCentral\Psr7\Response;

class EventSourceTest extends TestCase
{
    public function testConstructorThrowsIfFirstArgumentIsNotAnUri()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('///', $loop);
    }

    public function testConstructorThrowsIfUriArgumentDoesNotIncludeScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('example.com', $loop);
    }

    public function testConstructorThrowsIfUriArgumentIncludesInvalidScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->setExpectedException('InvalidArgumentException');
        new EventSource('ftp://example.com', $loop);
    }

    public function testConstructorCanBeCalledWithoutBrowser()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $es = new EventSource('http://example.invalid', $loop);

        $ref = new \ReflectionProperty($es, 'browser');
        $ref->setAccessible(true);
        $browser = $ref->getValue($es);

        $this->assertInstanceOf('React\Http\Browser', $browser);
    }


    public function testConstructorCanBeCalledWithoutCustomHeaders()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $es = new EventSource('http://example.valid', $loop);

        $ref = new ReflectionProperty($es, 'headers');
        $ref->setAccessible(true);
        $headers = $ref->getValue($es);

        $ref = new ReflectionProperty($es, 'defaultHeaders');
        $ref->setAccessible(true);
        $defaultHeaders = $ref->getValue($es);

        $this->assertEquals($defaultHeaders, $headers);
    }

    public function testConstructorCanBeCalledWithCustomHeaders()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $es = new EventSource('http://example.valid', $loop, null, array(
            'x-custom' => '1234',
            'Cache-Control' => 'only-if-cached',
            'ACCEPT' => 'no-store',
            'cache-control' => 'none'
        ));

        $ref = new ReflectionProperty($es, 'headers');
        $ref->setAccessible(true);
        $headers = $ref->getValue($es);

        // Could have used the defaultHeaders property on EventSource,
        // but this ensures the defaults are not altered by hardcoding their values in this test
        $this->assertEquals(array(
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'x-custom' => '1234'
        ), $headers);
    }

    public function testConstructorWillSendGetRequestThroughGivenBrowser()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->with(false)->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->with('GET', 'http://example.com')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);
    }

    public function testConstructorWillSendGetRequestThroughGivenBrowserWithHttpsScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->with(false)->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->with('GET', 'https://example.com')->willReturn($pending);

        $es = new EventSource('https://example.com', $loop, $browser);
    }

    public function testCloseWillCancelPendingGetRequest()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $cancelled = null;
        $pending = new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
        });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);
        $es->close();

        $this->assertEquals(1, $cancelled);
    }

    public function testCloseWillNotEmitErrorEventWhenGetRequestCancellationHandlerRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $pending = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

        $caught = null;
        $es->on('error', function ($e) use (&$caught) {
            $caught = $e;
        });
        $deferred->reject($expected = new \RuntimeException());

        $this->assertSame($expected, $caught);
    }

    public function testConstructorWillStartGetRequestThatWillNotStartRetryTimerWhenGetRequestRejectAndErrorHandlerClosesExplicitly()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

        $deferred->reject(new \RuntimeException());

        $es->close();
    }

    public function testConstructorWillReportFatalErrorWhenGetResponseResolvesWithInvalidStatusCode()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('React\Http\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withRejectErrorResponse')->willReturnSelf();
        $browser->expects($this->once())->method('requestStreaming')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

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

        $es = new EventSource('http://example.com', $loop, $browser);

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $deferred->resolve($response);

        $stream->write("id:123\n\n");
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
}
