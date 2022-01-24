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
}
