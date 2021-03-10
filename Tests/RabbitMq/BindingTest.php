<?php

namespace OldSound\RabbitMqBundle\Tests\RabbitMq;

use OldSound\RabbitMqBundle\RabbitMq\Binding;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BindingTest extends TestCase
{

    protected function getBinding(AMQPConnection $amqpConnection, AMQPChannel $amqpChannel): Binding
    {
        return new Binding($amqpConnection, $amqpChannel);
    }

    /**
     * @return MockObject | AMQPConnection
     */
    protected function prepareAMQPConnection()
    {
        return $this->getMockBuilder('\PhpAmqpLib\Connection\AMQPStreamConnection')
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return MockObject | AMQPChannel
     */
    protected function prepareAMQPChannel(?string $channelId = null)
    {
        $channelMock = $this->getMockBuilder('\PhpAmqpLib\Channel\AMQPChannel')
            ->disableOriginalConstructor()
            ->getMock();

        $channelMock->expects($this->any())
            ->method('getChannelId')
            ->willReturn($channelId);
        return $channelMock;
    }

    public function testQueueBind(): void
    {
        $ch = $this->prepareAMQPChannel('channel_id');
        $con = $this->prepareAMQPConnection();

        $source = 'example_source';
        $destination = 'example_destination';
        $key = 'example_key';
        $ch->expects($this->once())
            ->method('queue_bind')
            ->will($this->returnCallback(function ($d, $s, $k, $n, $a) use ($destination, $source, $key) {
                Assert::assertSame($destination, $d);
                Assert::assertSame($source, $s);
                Assert::assertSame($key, $k);
                Assert::assertFalse($n);
                Assert::assertNull($a);
            }));

        $binding = $this->getBinding($con, $ch);
        $binding->setExchange($source);
        $binding->setDestination($destination);
        $binding->setRoutingKey($key);
        $binding->setupFabric();
    }

    public function testExhangeBind(): void
    {
        $ch = $this->prepareAMQPChannel('channel_id');
        $con = $this->prepareAMQPConnection();

        $source = 'example_source';
        $destination = 'example_destination';
        $key = 'example_key';
        $ch->expects($this->once())
            ->method('exchange_bind')
            ->will($this->returnCallback(function ($d, $s, $k, $n, $a) use ($destination, $source, $key) {
                Assert::assertSame($destination, $d);
                Assert::assertSame($source, $s);
                Assert::assertSame($key, $k);
                Assert::assertFalse($n);
                Assert::assertNull($a);
            }));

        $binding = $this->getBinding($con, $ch);
        $binding->setExchange($source);
        $binding->setDestination($destination);
        $binding->setRoutingKey($key);
        $binding->setDestinationIsExchange(true);
        $binding->setupFabric();
    }
}
