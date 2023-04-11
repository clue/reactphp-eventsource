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

    public function testConstructWithDefaultLastEventIdAndType()
    {
        $message = new MessageEvent('hello');

        $this->assertEquals('hello', $message->data);
        $this->assertEquals('', $message->lastEventId);
        $this->assertEquals('message', $message->type);
    }

    public function testConstructWithEmptyDataAndId()
    {
        $message = new MessageEvent('', '');

        $this->assertEquals('', $message->data);
        $this->assertEquals('', $message->lastEventId);
        $this->assertEquals('message', $message->type);
    }

    public function testConstructWithNullBytesInDataAndType()
    {
        $message = new MessageEvent("h\x00llo!", '', "h\x00llo!");

        $this->assertEquals("h\x00llo!", $message->data);
        $this->assertEquals('', $message->lastEventId);
        $this->assertEquals("h\x00llo!", $message->type);
    }

    public function testConstructWithCarriageReturnAndLineFeedsInDataReplacedWithSimpleLineFeeds()
    {
        $message = new MessageEvent("hello\rworld!\r\n");

        $this->assertEquals("hello\nworld!\n", $message->data);
    }

    public function testConstructWithInvalidDataUtf8Throws()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $data given, must be valid UTF-8 string');
        new MessageEvent("h\xFFllo!");
    }

    public function testConstructWithInvalidLastEventIdUtf8Throws()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $lastEventId given, must be valid UTF-8 string with no null bytes or newline characters');
        new MessageEvent('hello', "h\xFFllo");
    }

    public function testConstructWithInvalidLastEventIdNullThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $lastEventId given, must be valid UTF-8 string with no null bytes or newline characters');
        new MessageEvent('hello', "h\x00llo");
    }

    public function testConstructWithInvalidLastEventIdCarriageReturnThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $lastEventId given, must be valid UTF-8 string with no null bytes or newline characters');
        new MessageEvent('hello', "hello\r");
    }

    public function testConstructWithInvalidLastEventIdLineFeedThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $lastEventId given, must be valid UTF-8 string with no null bytes or newline characters');
        new MessageEvent('hello', "hello\n");
    }

    public function testConstructWithInvalidTypeUtf8Throws()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $type given, must be valid UTF-8 string with no newline characters');
        new MessageEvent('hello', '', "h\xFFllo");
    }

    public function testConstructWithInvalidTypeEmptyThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $type given, must be valid UTF-8 string with no newline characters');
        new MessageEvent('hello', '', '');
    }

    public function testConstructWithInvalidTypeCarriageReturnThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $type given, must be valid UTF-8 string with no newline characters');
        new MessageEvent('hello', '', "hello\r");
    }

    public function testConstructWithInvalidTypeLineFeedThrows()
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid $type given, must be valid UTF-8 string with no newline characters');
        new MessageEvent('hello', '', "hello\r");
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
            // legacy PHPUnit < 5.2
            parent::setExpectedException($exception, $exceptionMessage, $exceptionCode);
        }
    }
}
