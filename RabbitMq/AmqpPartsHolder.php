<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

class AmqpPartsHolder
{
	protected $parts;

	public function __construct()
	{
		$this->parts = array();
	}

	public function addPart($type, BaseAmqp $part): void
	{
		$this->parts[$type][] = $part;
	}

	public function getParts($type): array
	{
        $type = (string) $type;
		return isset($this->parts[$type]) ? $this->parts[$type] : array();
	}
}
