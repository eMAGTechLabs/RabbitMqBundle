<?php

namespace OldSound\RabbitMqBundle\Tests\RabbitMq;

use OldSound\RabbitMqBundle\RabbitMq\BaseConsumer;
use PHPUnit\Framework\TestCase;

class BaseConsumerTest extends TestCase
{
    /** @var BaseConsumer */
    protected $consumer;

    protected function setUp(): void
    {
        $amqpConnection = $this->getMockBuilder('\PhpAmqpLib\Connection\AMQPConnection')
            ->disableOriginalConstructor()
            ->getMock();

        $this->consumer = $this->getMockBuilder('\OldSound\RabbitMqBundle\RabbitMq\BaseConsumer')
            ->setConstructorArgs(array($amqpConnection))
            ->getMockForAbstractClass();
    }

    public function testItExtendsBaseAmqpInterface(): void
    {
        $this->assertInstanceOf('OldSound\RabbitMqBundle\RabbitMq\BaseAmqp', $this->consumer);
    }

    public function testItImplementsDequeuerInterface(): void
    {
        $this->assertInstanceOf('OldSound\RabbitMqBundle\RabbitMq\DequeuerInterface', $this->consumer);
    }

    public function testItsIdleTimeoutIsMutable(): void
    {
        $this->assertEquals(0, $this->consumer->getIdleTimeout());
        $this->consumer->setIdleTimeout(42);
        $this->assertEquals(42, $this->consumer->getIdleTimeout());
    }

    public function testItsIdleTimeoutExitCodeIsMutable(): void
    {
        $this->assertEquals(0, $this->consumer->getIdleTimeoutExitCode());
        $this->consumer->setIdleTimeoutExitCode(43);
        $this->assertEquals(43, $this->consumer->getIdleTimeoutExitCode());
    }

    public function testForceStopConsumer(): void
    {
        $this->assertAttributeEquals(false, 'forceStop', $this->consumer);
        $this->consumer->forceStopConsumer();
        $this->assertAttributeEquals(true, 'forceStop', $this->consumer);
    }
}
