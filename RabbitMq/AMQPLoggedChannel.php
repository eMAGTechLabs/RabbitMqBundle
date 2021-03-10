<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * AMQPLoggedChannel.
 *
 * @author Marc Weistroff <marc.weistroff@sensio.com>
 */
class AMQPLoggedChannel extends AMQPChannel
{
    /** @var array */
    private $basicPublishLog = array();

    public function basic_publish($msg, $exchange = '', $routingKey = '', $mandatory = false, $immediate = false, $ticket = NULL): void
    {
        $this->basicPublishLog[] = array(
            'msg'         => $msg,
            'exchange'    => $exchange,
            'routing_key' => $routingKey,
            'mandatory'   => $mandatory,
            'immediate'   => $immediate,
            'ticket'      => $ticket
        );

        parent::basic_publish($msg, $exchange, $routingKey, $mandatory, $immediate, $ticket);
    }

    public function getBasicPublishLog(): array
    {
        return $this->basicPublishLog;
    }
}
