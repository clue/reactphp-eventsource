<?php

namespace Clue\Tests\React\EventSource;

use Clue\React\EventSource\MessageEvent;
use Clue\React\EventSource\SseDecoder;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;

class SseDecoderTest extends TestCase
{
    /** @var ThroughStream */
    private $input;

    /** @var SseDecoder */
    private $decoder;

    /**
     * @before
     */
    public function setUpDecoder()
    {
        $this->input = new ThroughStream();
        $this->decoder = new SseDecoder($this->input);
    }

    public function testConstructWillAssignEmptyLastEventIdAndNullRetryTime()
    {
        $this->assertSame('', $this->decoder->lastEventId);
        $this->assertNull($this->decoder->lastRetryTime);
    }

    public function testConstructWithLastEventIdWillUseAsIdForMessageWithoutId()
    {
        $this->decoder = new SseDecoder($this->input, '42');

        $message = null;
        $this->decoder->on('data', function ($m) use (&$message) {
            $message = $m;
        });

        $this->input->emit('data', array("data: hello\n\n"));

        $this->assertEquals('42', $message->lastEventId);
        $this->assertSame('42', $this->decoder->lastEventId);
    }

    public function testEmitDataWillForwardMessageEventWithParsedData()
    {
        $message = null;
        $this->decoder->on('data', function ($m) use (&$message) {
            $message = $m;
        });

        $this->input->emit('data', array("data: hello\n\n"));

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello', $message->data);
        $this->assertEquals('message', $message->type);
        $this->assertEquals('', $message->lastEventId);
    }

    public function testEmitDataWillForwardCustomEventTypeAndId()
    {
        $message = null;
        $this->decoder->on('data', function ($m) use (&$message) {
            $message = $m;
        });

        $this->input->emit('data', array("event: patch\nid: 1\ndata: hello\n\n"));

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello', $message->data);
        $this->assertEquals('patch', $message->type);
        $this->assertEquals('1', $message->lastEventId);
    }

    public function testEmitDataWillForwardDataOverMultipleLinesCombined()
    {
        $message = null;
        $this->decoder->on('data', function ($m) use (&$message) {
            $message = $m;
        });

        $this->input->emit('data', array("data: hello\ndata: world\n\n"));

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testEmitTwoEventsInSingleChunkWillForwardTwoMessageEvents()
    {
        $messages = [];
        $this->decoder->on('data', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $this->input->emit('data', array("data: hello\n\ndata: world\n\n"));

        $this->assertCount(2, $messages);
        $this->assertEquals('hello', $messages[0]->data);
        $this->assertEquals('world', $messages[1]->data);
    }

    public function testEmitEventOverMultipleChunksWillForwardSingleMessageEventOnceComplete()
    {
        $messages = [];
        $this->decoder->on('data', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $this->input->emit('data', array("data: hel"));
        $this->assertCount(0, $messages);

        $this->input->emit('data', array("lo\n"));
        $this->assertCount(0, $messages);

        $this->input->emit('data', array("\n"));
        $this->assertCount(1, $messages);
        $this->assertEquals('hello', $messages[0]->data);
    }

    public function testEmitEventWillInheritLastEventIdAcrossEvents()
    {
        $messages = [];
        $this->decoder->on('data', function ($m) use (&$messages) {
            $messages[] = $m;
        });

        $this->input->emit('data', array("id: 100\ndata: first\n\ndata: second\n\n"));

        $this->assertCount(2, $messages);
        $this->assertEquals('100', $messages[0]->lastEventId);
        $this->assertEquals('100', $messages[1]->lastEventId);
    }

    public function testEmitEventWithCarriageReturnLineFeedBoundaryWillForwardMessageEvent()
    {
        $message = null;
        $this->decoder->on('data', function ($m) use (&$message) {
            $message = $m;
        });

        $this->input->emit('data', array("data: hello\r\n\r\n"));

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello', $message->data);
    }

    public function testEmitCommentOnlyEventWithoutDataWillNotForwardMessageEvent()
    {
        $this->decoder->on('data', function () {
            $this->fail('Did not expect data event');
        });

        $this->input->emit('data', array(": this is a comment\n\n"));

        $this->assertTrue($this->decoder->isReadable());
    }

    public function testEmitIncompleteEventWithoutBoundaryWillNotForwardMessageEvent()
    {
        $this->decoder->on('data', function () {
            $this->fail('Did not expect data event');
        });

        $this->input->emit('data', array("data: hello\n"));

        $this->assertTrue($this->decoder->isReadable());
    }

    public function testEmitIncompleteEventThenEndWillNotForwardMessageEventAndDiscardBuffer()
    {
        $this->decoder->on('data', function () {
            $this->fail('Did not expect data event');
        });
        $ended = false;
        $this->decoder->on('end', function () use (&$ended) {
            $ended = true;
        });

        $this->input->emit('data', array("data: hello\n"));
        $this->input->emit('end');

        $this->assertTrue($ended);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testEmitEndWillForwardEnd()
    {
        $ended = false;
        $this->decoder->on('end', function () use (&$ended) {
            $ended = true;
        });
        $this->decoder->on('close', function () use (&$ended) {
            $this->assertTrue($ended);
        });

        $this->input->emit('end');

        $this->assertTrue($ended);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testEmitDataWithInvalidTypeWillForwardErrorAndClose()
    {
        $error = null;
        $this->decoder->on('error', function ($e) use (&$error) {
            $error = $e;
        });
        $this->decoder->on('close', function () use (&$error) {
            $this->assertNotNull($error);
        });

        $this->input->emit('data', array(false));

        $this->assertInstanceOf('UnexpectedValueException', $error);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testEmitRetryOnlyEventWillAssignRetryTimeAndNotForwardMessageEvent()
    {
        $this->decoder->on('data', function () {
            $this->fail('Did not expect data event');
        });

        $this->input->emit('data', array("retry: 2543\n\n"));

        $this->assertSame(2.543, $this->decoder->lastRetryTime);
    }

    public function testEmitIdOnlyEventWillAssignLastEventIdAndNotForwardMessageEvent()
    {
        $this->decoder->on('data', function () {
            $this->fail('Did not expect data event');
        });

        $this->input->emit('data', array("id: 42\n\n"));

        $this->assertSame('42', $this->decoder->lastEventId);
    }

    public function testEmitEventWillAssignRetryTimeBeforeForwardingMessageEvent()
    {
        $retryTime = null;
        $this->decoder->on('data', function () use (&$retryTime) {
            $retryTime = $this->decoder->lastRetryTime;
        });

        $this->input->emit('data', array("retry: 2543\ndata: hello\n\n"));

        $this->assertSame(2.543, $retryTime);
    }

    public function testEmitErrorEventWillForwardErrorAndClose()
    {
        $error = null;
        $this->decoder->on('error', function ($e) use (&$error) {
            $error = $e;
        });
        $this->decoder->on('close', function () use (&$error) {
            $this->assertNotNull($error);
        });

        $exception = new \RuntimeException();
        $this->input->emit('error', array($exception));

        $this->assertSame($exception, $error);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingDecoderDuringDataEventWillNotForwardFurtherMessageEvents()
    {
        $messages = [];
        $this->decoder->on('data', function ($m) use (&$messages) {
            $messages[] = $m;
        });
        $this->decoder->on('data', array($this->decoder, 'close'));

        $this->input->emit('data', array("data: hello\n\ndata: world\n\n"));

        $this->assertCount(1, $messages);
        $this->assertEquals('hello', $messages[0]->data);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingInputWillCloseDecoder()
    {
        $closed = false;
        $this->decoder->on('close', function () use (&$closed) {
            $closed = true;
        });

        $this->assertTrue($this->decoder->isReadable());

        $this->input->close();

        $this->assertTrue($closed);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingInputWillRemoveAllDataListeners()
    {
        $this->decoder->on('data', function () { });

        $this->input->close();

        $this->assertEquals([], $this->input->listeners('data'));
        $this->assertEquals([], $this->decoder->listeners('data'));
    }

    public function testClosingDecoderWillCloseInput()
    {
        $closed = false;
        $this->input->on('close', function () use (&$closed) {
            $closed = true;
        });

        $this->assertTrue($this->decoder->isReadable());

        $this->decoder->close();

        $this->assertTrue($closed);
        $this->assertFalse($this->decoder->isReadable());
    }

    public function testClosingDecoderTwiceWillCloseInputOnce()
    {
        $closed = 0;
        $this->decoder->on('close', function () use (&$closed) {
            ++$closed;
        });

        $this->decoder->close();
        $this->decoder->close();

        $this->assertEquals(1, $closed);
    }

    public function testUnreadableInputWillResultInUnreadableDecoder()
    {
        $this->input->close();
        $this->decoder = new SseDecoder($this->input);

        $this->assertFalse($this->decoder->isReadable());
    }

    public function testUnreadableInputWillNotAddAnyEventListeners()
    {
        $this->input->close();
        $this->decoder = new SseDecoder($this->input);

        $this->assertEquals([], $this->input->listeners('data'));
        $this->assertEquals([], $this->decoder->listeners('data'));
    }

    public function testPipeReturnsDestStream()
    {
        $dest = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();

        $ret = $this->decoder->pipe($dest);

        $this->assertSame($dest, $ret);
    }

    public function testForwardPauseToInput()
    {
        $input = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $input->expects($this->once())->method('isReadable')->willReturn(true);
        $input->expects($this->once())->method('pause');

        $decoder = new SseDecoder($input);
        $decoder->pause();
    }

    public function testForwardResumeToInput()
    {
        $input = $this->getMockBuilder('React\Stream\ReadableStreamInterface')->getMock();
        $input->expects($this->once())->method('isReadable')->willReturn(true);
        $input->expects($this->once())->method('resume');

        $decoder = new SseDecoder($input);
        $decoder->resume();
    }
}
