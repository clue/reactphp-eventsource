<?php

namespace Clue\Tests\React\EventSource;

use PHPUnit\Framework\TestCase;
use Clue\React\EventSource\MessageEvent;

class MessageEventTest extends TestCase
{
    public function testParseSimpleData()
    {
        $message = MessageEvent::parse("data: hello");

        $this->assertEquals('hello', $message->data);
    }

    public function testParseDataOverTwoLinesWillBeCombined()
    {
        $message = MessageEvent::parse("data: hello\ndata: world");

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataOverTwoLinesWithCarrigeReturnsWillBeCombinedWithNewline()
    {
        $message = MessageEvent::parse("data: hello\rdata: world");

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataOverTwoLinesWithCarrigeReturnsAndNewlinesWillBeCombinedWithNewline()
    {
        $message = MessageEvent::parse("data: hello\r\ndata: world");

        $this->assertEquals("hello\nworld", $message->data);
    }

    public function testParseDataWithTrailingNewlineOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\ndata:");

        $this->assertEquals("hello\n", $message->data);
    }

    public function testParseDataWithCarrigeReturnOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\rdata:");

        $this->assertEquals("hello\n", $message->data);
    }

    public function testParseDataWithCarrigeReturnAndNewlineOverTwoLines()
    {
        $message = MessageEvent::parse("data: hello\r\ndata:");

        $this->assertEquals("hello\n", $message->data);
    }

    public function retryTimeDataProvider()
    {
        return [
            ['retry: 1234', 1234,],
            ['retry: 0', 0,],
            ['retry: ' . PHP_INT_MAX, PHP_INT_MAX,],
            ['retry: ' . PHP_INT_MAX . '9', null,],
            ['retry: 1.234', null,],
            ['retry: now', null,],
            ['retry: -1', null,],
            ['retry: -1.234', null,],
        ];
    }

    /**
     * @dataProvider retryTimeDataProvider
     */
    public function testParseRetryTime($input, $expected)
    {
        $message = MessageEvent::parse($input);

        $this->assertSame($expected, $message->retry);
    }
}
