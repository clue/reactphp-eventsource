<?php

namespace Clue\Tests\React\EventSource;

use PHPUnit\Framework\TestCase;
use Clue\React\EventSource\MessageEvent;

class MessageEventTest extends TestCase
{
    public function testParseSimpleData()
    {
        $message = MessageEvent::parse("data: hello", '');

        $this->assertEquals('hello', $message->data);
    }

    public function testParseDataOverTwoLinesWillBeCombined()
    {
        $message = MessageEvent::parse("data: hello\ndata: world", '');

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataOverTwoLinesWithCarrigeReturnsWillBeCombinedWithNewline()
    {
        $message = MessageEvent::parse("data: hello\rdata: world", '');

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataOverTwoLinesWithCarrigeReturnsAndNewlinesWillBeCombinedWithNewline()
    {
        $message = MessageEvent::parse("data: hello\r\ndata: world", '');

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataWithTrailingNewlineOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\ndata:", '');

        $this->assertEquals("hello\n", $message->data);
    }

    public function testParseDataWithCarrigeReturnOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\rdata:", '');

        $this->assertEquals("hello\n", $message->data);
    }

    public function testParseDataWithCarrigeReturnAndNewlineOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\r\ndata:", '');

        $this->assertEquals("hello\n", $message->data);
    }

    public function testParseDataWithNonUtf8AndNullBytesReturnsDataWithUnicodeReplacement()
    {
        $message = MessageEvent::parse("data: h\x00ll\xFF!", '');

        $this->assertEquals("h\x00ll�!", $message->data);
    }

    public function testParseReturnsMessageWithIdFromStream()
    {
        $message = MessageEvent::parse("data: hello\r\nid: 1", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('1', $message->lastEventId);
    }

    public function testParseWithoutIdReturnsMessageWithIdFromLastEventId()
    {
        $message = MessageEvent::parse("data: hello", '1');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('1', $message->lastEventId);
    }

    public function testParseWithoutIdReturnsMessageWithEmptyIdIfLastEventIdIsEmpty()
    {
        $message = MessageEvent::parse("data: hello", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('', $message->lastEventId);
    }

    public function testParseWithMultipleIdsReturnsMessageWithLastEventIdFromStream()
    {
        $message = MessageEvent::parse("data: hello\nid: 1\nid: 2", '0');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('2', $message->lastEventId);
    }

    public function testParseWithIdWithNonUtf8BytesReturnsMessageWithLastEventIdFromStreamWithUnicodeReplacement()
    {
        $message = MessageEvent::parse("data: hello\nid: h\xFFllo!", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals("h�llo!", $message->lastEventId);
    }

    public function testParseWithIdWithNullByteReturnsMessageWithLastEventIdFromLastEventId()
    {
        $message = MessageEvent::parse("data: hello\nid: h\x00llo!", '1');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('1', $message->lastEventId);
    }

    public function testParseReturnsMessageWithTypeFromStream()
    {
        $message = MessageEvent::parse("data: hello\r\nevent: join", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('join', $message->type);
    }

    public function testParseWithoutEventReturnsMessageWithDefaultMessageType()
    {
        $message = MessageEvent::parse("data: hello", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('message', $message->type);
    }

    public function testParseWithMultipleEventsReturnsMessageWithLastTypeFromStream()
    {
        $message = MessageEvent::parse("data: hello\nevent: join\nevent: leave", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('leave', $message->type);
    }

    public function testParseWithEmptyEventReturnsMessageWithDefaultMessageType()
    {
        $message = MessageEvent::parse("data: hello\r\nevent:", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals('message', $message->type);
    }

    public function testParseWithEventTypeWithNonUtf8AndNullBytesReturnsTypeWithUnicodeReplacement()
    {
        $message = MessageEvent::parse("data: hello\nevent: h\x00ll\xFF!", '');

        $this->assertEquals("hello", $message->data);
        $this->assertEquals("h\x00ll�!", $message->type);
    }

    public function retryTimeDataProvider()
    {
        return [
            ['retry: 1234', 1.234],
            ['retry: 0', 0.0],
            ['retry: ' . PHP_INT_MAX, PHP_INT_MAX * 0.001],
            ['retry: ' . PHP_INT_MAX . '9', null],
            ['retry: 1.234', null],
            ['retry: now', null],
            ['retry: -1', null],
            ['retry: -1.234', null]
        ];
    }

    /**
     * @dataProvider retryTimeDataProvider
     */
    public function testParseRetryTime($input, $expected)
    {
        $retryTime = null;
        MessageEvent::parse($input, '', $retryTime);

        $this->assertSame($expected, $retryTime);
    }
}
