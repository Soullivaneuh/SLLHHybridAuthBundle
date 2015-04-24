<?php

namespace SLLH\HybridAuthBundle\DependencyInjection\Security\Factory;

use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AbstractFactory;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Description of HybridAuthFactory
 *
 * @author Sullivan SENECHAL <soullivaneuh@gmail.com>
 */
class HybridAuthFactory extends AbstractFactory
{
    /**
     * @{inheritDoc}
     */
    public function create(ContainerBuilder $container, $id, $config, $userProviderId, $defaultEntryPointId)
    {
        $container->register($this->getHybridAuthLogoutHandlerReference(), '%sllh_hybridauth.http.logout.handler.hybridauth.class%')
                ->addArgument(new Reference('sllh_hybridauth.provider_map'))
        ;

        $container->getDefinition('security.logout_listener')
                ->addMethodCall('addHandler', array($this->getHybridAuthLogoutHandlerReference()))
        ;
        return parent::create($container, $id, $config, $userProviderId, $defaultEntryPointId);
    }

    /**
     * Creates a resource owner map for the given configuration.
     *
     * @param ContainerBuilder $container Container to build for
     * @param string           $id        Firewall id
     * @param array            $config    Configuration
     */
    protected function createHybridAuthProviderMap(ContainerBuilder $container, $id, array $config)
    {
        $providers = array();
        foreach ($config['providers_check_path'] as $name => $checkPath) {
            $providers[$name] = $checkPath;
        }
        $container->setParameter('sllh_hybridauth.provider_map.configured.'.$id, $providers);

        $providerMapDefinition = $container
            ->register($this->getHybridAuthProviderMapReference(), '%sllh_hybridauth.provider_map.class%')
            ->addArgument(new Reference('service_container'))
            ->addArgument(new Reference('security.http_utils'))
            ->addArgument(new Parameter('sllh_hybridauth.provider_map.configured.'.$id))
        ;
    }

    /**
     * Get a reference to the logout listener
     */
    protected function getHybridAuthLogoutHandlerReference()
    {
        return new Reference('security.http.logout.handler.hybridauth');
    }

    /**
     * Get a reference to the HybridAuth provider map
     */
    protected function getHybridAuthProviderMapReference()
    {
        return new Reference('sllh_hybridauth.provider_map');
    }

    /**
     * {@inheritDoc}
     */
    protected function createAuthProvider(ContainerBuilder $container, $id, $config, $userProviderId)
    {
//        $providerId = 'sllh_hybridauth.authentication.provider.hybridauth.'.$id;
        $providerId = 'security.authentication.provider.hybridauth.'.$id;

        $this->createHybridAuthProviderMap($container, $id, $config);

        $container
            ->setDefinition($providerId, new DefinitionDecorator('security.authentication.provider.hybridauth'))
            ->addArgument($this->createHybridAuthAwareUserProvider($container, $id, $config['hybridauth_user_provider']))
            ->addArgument($this->getHybridAuthProviderMapReference())
            ->addArgument(new Reference('sllh_hybridauth.user_checker'))
        ;
        return $providerId;
    }

    /**
     * Assign the selected userProvider
     *
     * @param ContainerBuilder $container
     * @param string $id
     * @param array $config
     *
     * @return \Symfony\Component\DependencyInjection\Reference
     */
    protected function createHybridAuthAwareUserProvider(ContainerBuilder $container, $id, $config)
    {
        $serviceId = 'sllh_hybridauth.user.provider.entity.'.$id;

        switch(key($config)) { // TODO: add a default provider
//            case 'hybridauth':
//                $container
//                    ->setDefinition($serviceId, new DefinitionDecorator('sllh_hybridauth.user.provider'));
//                break;
            case 'service':
                $container
                    ->setAlias($serviceId, $config['service']);
                break;
        }

        return new Reference($serviceId);
    }

    /**
     * {@inheritDoc}
     */
    protected function createEntryPoint($container, $id, $config, $defaultEntryPoint)
    {
        $entryPointId = 'security.authentication.entry_point.hybridauth.'.$id;

        $entryPointDefinition = $container
            ->setDefinition($entryPointId, new DefinitionDecorator('security.authentication.entry_point.hybridauth'))
            ->addArgument(new Reference('security.http_utils'))
            ->addArgument($config['login_path'])
        ;

        return $entryPointId;
    }

    /**
     * {@inheritDoc}
     */
    protected function createListener($container, $id, $config, $userProvider)
    {
        $listenerId = parent::createListener($container, $id, $config, $userProvider);

        $checkPaths = array();
        foreach ($config['providers_check_path'] as $checkPath) {
            $checkPaths[] = $checkPath;
        }

        $container->getDefinition($listenerId)
            ->addMethodCall('setProviderMap', array($this->getHybridAuthProviderMapReference()))
            ->addMethodCall('setCheckPaths', array($checkPaths));

        return $listenerId;
    }

    /**
     * {@inheritDoc}
     */
    public function addConfiguration(NodeDefinition $node)
    {
        parent::addConfiguration($node);

        $builder = $node->children();

        $builder
            ->scalarNode('login_path')
                ->cannotBeEmpty()
                ->isRequired()
            ->end()
            ->arrayNode('providers_check_path')
                ->isRequired()
                ->useAttributeAsKey('name')
                ->prototype('scalar')
                ->end()
                ->validate()
                    ->ifTrue(function($c) {
                        $checkPaths = array();
                        foreach ($c as $name => $checkPath) {
                            if (in_array($checkPath, $checkPaths)) {

                                return true;
                            }

                            $checkPaths[] = $checkPath;
                        }

                        return false;
                    })
                    ->thenInvalid("Each providers should have a unique check_path.")
                ->end()
            ->end()
            ->arrayNode('hybridauth_user_provider') // TODO: add more providers (orm, fos...)
                ->isRequired()
                ->children()
                    ->scalarNode('service')
                        ->cannotBeEmpty()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(function($up) {
                        return 1 !== count($up) || !in_array(key($up), array('service'));
                    })
                    ->thenInvalid("You should configure (only) one of: 'service'.")
                ->end()
            ->end()
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function getListenerId()
    {
        return 'security.authentication.listener.hybridauth';
    }

    /**
     * {@inheritDoc}
     */
    public function getKey()
    {
        return 'hybridauth';
    }

    /**
     * {@inheritDoc}
     */
    public function getPosition()
    {
        return 'http';
    }
}

?>
