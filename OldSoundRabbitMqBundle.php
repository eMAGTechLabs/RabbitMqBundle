<?php

namespace OldSound\RabbitMqBundle;

use OldSound\RabbitMqBundle\DependencyInjection\Compiler\ConsumersListCompilerPass;
use OldSound\RabbitMqBundle\DependencyInjection\Compiler\ExtraContextLoggerCompilerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OldSoundRabbitMqBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ExtraContextLoggerCompilerPass());
        $container->addCompilerPass(new ConsumersListCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }

    /**
     * {@inheritDoc}
     */
    public function shutdown()
    {
        parent::shutdown();

        /* TODO $connections = $this->container->getParameter('old_sound_rabbit_mq.connection');
        foreach ($connections as $connection) {
            if ($this->container->initialized($connection)) {
                $this->container->get($connection)->close();
            }
        }*/
    }
}
