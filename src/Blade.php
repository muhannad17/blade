<?php

namespace BC\Blade;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\DynamicComponent;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\FileEngine;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewFinderInterface;

/**
 * @method \Illuminate\Contracts\View\View make($view, $data = [], $mergeData = [])
 * @method \Illuminate\Contracts\View\View with($key, $value = null)
 * @method void directive($name, callable $handler)
 */
class Blade
{
    protected array $viewPaths;

    protected string $compiledPath;

    protected DispatcherContract $events;

    protected Filesystem $files;

    protected EngineResolver $resolver;

    protected CompilerInterface $bladeCompiler;

    protected FileViewFinder $finder;

    protected Factory $view;

    protected Container $app;

    /**
     * @param string|array $view_paths
     */
    public function __construct($view_paths, $compiled_path, $cache_path)
    {
        $this->app = Container::getInstance();

        $this->app->alias('view', \Illuminate\Contracts\View\Factory::class);
        $this->app->alias('app', \Illuminate\Contracts\Foundation\Application::class);

        $this->app->singleton('config', \Illuminate\Config\Repository::class);
        $this->app->singleton('app', \Illuminate\Foundation\Application::class);
        $this->app->singleton('files', Filesystem::class);
        $this->app->singleton('events', Dispatcher::class);


        $this->viewPaths = (array)$view_paths;
        $this->compiledPath = (string)$compiled_path;

        $this->register();

        $this->app->make('config')->set('view.compiled', $cache_path);
    }

    /**
     * Undefined methods are proxied to the compiler
     * and the view factory for API ease of use.
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        if (method_exists($this->app->make('blade.compiler'), $name)) {
            return $this->app->make('blade.compiler')->{$name}(...$arguments);
        }

        if (method_exists($this->app->make('view'), $name)) {
            return $this->app->make('view')->{$name}(...$arguments);
        }
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFactory();
        $this->registerViewFinder();
        $this->registerBladeCompiler();
        $this->registerEngineResolver();
    }

    /**
     * Register the view environment.
     *
     * @return void
     */
    public function registerFactory()
    {
        $this->app->singleton('view', function ($app) {
            // Next we need to grab the engine resolver instance that will be used by the
            // environment. The resolver will be used by an environment to get each of
            // the various engine implementations such as plain PHP or Blade engine.

            echo 2;
            $resolver = $app['view.engine.resolver'];

            $finder = $app['view.finder'];

            $factory = $this->createFactory($resolver, $finder, $app['events']);


            // We will also set the container instance on this view environment since the
            // view composers may be classes registered in the container, which allows
            // for great testable, flexible composers for the application developer.
            $factory->setContainer($app);

            echo 3;
            $factory->share('app', $app);

            return $factory;
        });
    }

    /**
     * Create a new Factory Instance.
     *
     * @param \Illuminate\View\Engines\EngineResolver $resolver
     * @param \Illuminate\View\ViewFinderInterface    $finder
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     * @return \Illuminate\View\Factory
     */
    protected function createFactory($resolver, $finder, $events)
    {
        return new Factory($resolver, $finder, $events);
    }

    /**
     * Register the view finder implementation.
     *
     * @return void
     */
    public function registerViewFinder()
    {
        $this->app->bind('view.finder', function ($app) {
            echo "T<br/>";ob_flush();
            return new FileViewFinder($app['files'], $this->viewPaths);
        });
    }

    /**
     * Register the Blade compiler implementation.
     *
     * @return void
     */
    public function registerBladeCompiler()
    {
        $this->app->singleton('blade.compiler', function ($app) {
            return tap(new BladeCompiler($app['files'], $app['config']['view.compiled']), function ($blade) {
                $blade->component('dynamic-component', DynamicComponent::class);
            });
        });
    }

    /**
     * Register the engine resolver instance.
     *
     * @return void
     */
    public function registerEngineResolver()
    {
        $this->app->singleton('view.engine.resolver', function () {
            $resolver = new EngineResolver;

            // Next, we will register the various view engines with the resolver so that the
            // environment will resolve the engines needed for various views based on the
            // extension of view file. We call a method for each of the view's engines.
            foreach (['file', 'php', 'blade'] as $engine) {
                $this->{'register' . ucfirst($engine) . 'Engine'}($resolver);
            }

            return $resolver;
        });
    }

    /**
     * Register the file engine implementation.
     *
     * @param \Illuminate\View\Engines\EngineResolver $resolver
     * @return void
     */
    public function registerFileEngine($resolver)
    {
        $resolver->register('file', function () {
            return new FileEngine($this->app['files']);
        });
    }

    /**
     * Register the PHP engine implementation.
     *
     * @param \Illuminate\View\Engines\EngineResolver $resolver
     * @return void
     */
    public function registerPhpEngine($resolver)
    {
        $resolver->register('php', function () {
            return new PhpEngine($this->app['files']);
        });
    }

    /**
     * Register the Blade engine implementation.
     *
     * @param \Illuminate\View\Engines\EngineResolver $resolver
     * @return void
     */
    public function registerBladeEngine($resolver)
    {
        $resolver->register('blade', function () {
            return new CompilerEngine($this->app['blade.compiler'], $this->app['files']);
        });
    }
}
