<?php

namespace OldSound\RabbitMqBundle\Logger;

use Psr\Log\LoggerInterface;

class ExtraContextLogger implements LoggerInterface
{
    /** @var LoggerInterface */
    private $origin;
    /** @var array */
    private $extraContext;

    public function __constract(LoggerInterface $origin, array $extraContext)
    {
        $this->origin = $origin;
        $this->extraContext = $extraContext;
    }

    public function emergency($message, array $context = array())
    {
        $this->origin->emergency($message, $this->modifyCotenxt($context));
    }

    public function alert($message, array $context = array())
    {
        $this->origin->alert($message, $this->modifyCotenxt($context));
    }

    public function critical($message, array $context = array())
    {
        $this->origin->critical($message, $this->modifyCotenxt($context));
    }

    public function error($message, array $context = array())
    {
        $this->origin->error($message, $this->modifyCotenxt($context));
    }

    public function warning($message, array $context = array())
    {
        $this->origin->warning($message, $this->modifyCotenxt($context));
    }

    public function notice($message, array $context = array())
    {
        $this->origin->notice($message, $this->modifyCotenxt($context));
    }

    public function info($message, array $context = array())
    {
        $this->origin->info($message, $this->modifyCotenxt($context));
    }

    public function debug($message, array $context = array())
    {
        $this->origin->debug($message, $this->modifyCotenxt($context));
    }

    public function log($level, $message, array $context = array())
    {
        $this->origin->log($message, $this->modifyCotenxt($context));
    }

    private function modifyCotenxt(array $context)
    {
        return array_merge_recursive($context, $this->extraContext);
    }
}