<?php
namespace Slince\Di\Tests;

use PHPUnit\Framework\TestCase;
use Slince\Di\Container;
use Slince\Di\Definition;
use Slince\Di\Exception\ConfigException;
use Slince\Di\Exception\DependencyInjectionException;
use Slince\Di\Exception\NotFoundException;
use Slince\Di\Reference;
use Slince\Di\Tests\TestClass\Actor;
use Slince\Di\Tests\TestClass\ActorInterface;
use Slince\Di\Tests\TestClass\Actress;
use Slince\Di\Tests\TestClass\Director;
use Slince\Di\Tests\TestClass\Movie;

class ContainerTest extends TestCase
{
    public function testFactory()
    {
        $container = new Container();
        $container->register('director1', function () {
            return new Director('James', 26);
        });
        $this->assertInstanceOf(Director::class, $container->get('director1'));
        $container->register('director2', [Director::class, 'factory']);

        $this->assertInstanceOf(Director::class, $container->get('director2'));
    }

    public function testFactoryWithParameters()
    {
        $container = new Container();
        $container->register('director', function ($age, $name) {
            return new Director($name, $age);
        })
        ->setArguments(['name' => 'James', 'age' => 26]);
        $director = $container->get('director');
        $this->assertEquals('James', $director->getName());
        $this->assertEquals(26, $director->getAge());
    }

    public function testInstance()
    {
        $container = new Container();
        $director = new Director();
        $container->register('director', $director);
        $this->assertTrue($container->has('director'));
        $this->assertInstanceOf(Director::class, $container->get('director'));
        $this->assertTrue($container->get('director') === $director);
        $this->assertTrue($container->get('director') === $container->get('director'));

        $container->register(new Director());
        $this->assertTrue($container->has(Director::class));
    }

    public function testDefine()
    {
        $container = new Container();
        $container->register('director', Director::class)
            ->setArguments( [0 => 'Bob', 1 => 45]);
        $this->assertInstanceOf(Director::class, $director = $container->get('director'));
        $this->assertEquals('Bob', $director->getName());
        $this->assertEquals(45, $director->getAge());
    }

    public function testBind()
    {
        $container = new Container();
        $container->register('director', Director::class);
        $this->assertInstanceOf(Director::class, $container->get('director'));
    }

    public function testInterfaceBind()
    {
        $container = new Container();
        $container->register(ActorInterface::class, Actor::class);
        $this->assertInstanceOf(ActorInterface::class, $container->get(ActorInterface::class));
        $this->assertInstanceOf(Actor::class, $container->get(ActorInterface::class));

        $movie = $container->get(Movie::class);
        $this->assertInstanceOf(Movie::class, $movie);
        $this->assertInstanceOf(Actor::class, $movie->getActor());
    }

    public function testHas()
    {
        $container = new Container();
        $this->assertFalse($container->has('not_exists_class'));
        $this->assertTrue($container->has(Director::class));

        $container->register(new Director());
        $container->get(Director::class);
        $this->assertTrue($container->has(Director::class));

        $container->register(ActorInterface::class, Actor::class);
        $this->assertTrue($container->has(ActorInterface::class));

        $container = new Container();
        $container->register(new Director());
        $this->assertTrue($container->has(Director::class));
    }

    public function testGetWithMissingRequiredParameters()
    {
        $container = new Container();
        $container->register('director', function($name, $age){
            return new Director($name, $age);
        });
        $this->expectException(DependencyInjectionException::class);
        $container->get('director');
    }

    public function testGetWithMissingOptionalClassDependency()
    {
        $container = new Container();
        $container->register('director', function($name, $age, ActorInterface $actor = null){
            $this->assertNull($actor);
            return new Director($name, $age);
        })->setArguments([
            'name' => 'bob',
            'age' => 12
        ]);
        $container->get('director');
    }

    public function testShare()
    {
        $container = new Container();
        $container->register('director', function () {
            return new Director('James', 26);
        })->setShared(true);
        $this->assertTrue($container->get('director') === $container->get('director'));

        $container->register('director2', function () {
            return new Director('James', 26);
        })->setShared(false);
        $this->assertFalse($container->get('director2') === $container->get('director2'));
    }

    public function testConfigureShare()
    {
        $container = new Container();
        $container->setDefaults([
            'share' => false
        ]);
        $container->register('director', function () {
            return new Director('James', 26);
        });
        $this->assertFalse($container->get('director') === $container->get('director'));
    }

    public function testAutowire()
    {
        $container = new Container();
        $container->register(Movie::class)
            ->setAutowired(false);

        try {
            $container->get(Movie::class);
            $this->fail();
        } catch (\Exception $exception) {
            $this->assertInstanceOf(DependencyInjectionException::class, $exception);
        }
    }

    public function testConfigureAutowire()
    {
        $container = new Container();
        $container->setDefaults([
            'autowire' => false
        ]);
        $container->register(Movie::class);

        try {
            $container->get(Movie::class);
            $this->fail();
        } catch (\Exception $exception) {
            $this->assertInstanceOf(DependencyInjectionException::class, $exception);
        }
    }

    public function testParameters()
    {
        $container = new Container();
        $container->setParameters([
            'foo' => 'bar'
        ]);
        $this->assertEquals('bar', $container->getParameter('foo'));
        $container->addParameters([
            'foo' => 'baz',
            'bar' => 'baz'
        ]);
        $this->assertEquals(['foo' => 'baz', 'bar' => 'baz'], $container->getParameters());
        $container->setParameter('bar', 'baz');
        $this->assertEquals('baz', $container->getParameter('bar'));
    }

    public function testResolveParameters()
    {
        $container = new Container();
        $container->setParameters([
            'foo' => 'James',
            'bar' => 45
        ]);

        $container->register('director', function(array $profile){
            return new Director($profile['name'], $profile['age']);
        })->setArguments([
            'profile' => [
                'name' => '%foo% Bob',
                'age' => '%bar%',
            ]
        ]);
        $director = $container->get('director');
        $this->assertEquals('James Bob', $director->getName());
        $this->assertEquals(45, $director->getAge());

        try {
            $container->register('director2', function(array $profile){
                return new Director($profile['name'], $profile['age']);
            })->setArguments([
                'profile' => [
                    'name' => '%baz% Bob',
                    'age' => '%bar%',
                ]
            ]);
            $container->get('director2');
            $this->fail();
        } catch (\Exception $exception) {
            $this->assertContains('is not defined', $exception->getMessage());
        }
    }

    public function testSimpleGlobalParameter()
    {
        $container = new Container();
        $container->setParameters([
            'directorName' => 'James'
        ]);
        $container->register('director', function (Container $container) {
            return new Director($container->getParameter('directorName'), 26);
        });
        $this->assertEquals('James', $container->get('director')->getName());
    }

    public function testGlobalParameterUseDotAccess()
    {
        $container = new Container();
        $container->setParameters([
            'directorName' => 'James',
            'director' => [
                'age' => 26
            ]
        ]);
        $container->register('director', Director::class)->setArguments([
            '%directorName%',
            '%director.age%'
        ]);
        $this->assertEquals('James', $container->get('director')->getName());
        $this->assertEquals(26, $container->get('director')->getAge());
    }

    public function testAlias()
    {
        $container = new Container();

        $container->register('director', function(array $profile){
            return new Director($profile['name'], $profile['age']);
        })->setArguments([
            'profile' => [
                'name' => 'James',
                'age' => 45,
            ]
        ]);
        $container->setAlias('director-alias', 'director');
        $this->assertEquals('director', $container->getAlias('director-alias'));
        $this->assertSame($container->get('director'), $container->get('director-alias'));
    }
}
