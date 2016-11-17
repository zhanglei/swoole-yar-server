<?php
namespace Easth\Server;

use Error;
use Exception;
use Closure;
use Illuminate\Container\Container;
use Symfony\Component\Debug\Exception\FatalThrowableError;

class Application extends Container
{
    protected $routes = [];

    protected $groupAttributes;

    protected $currentRoute;

    protected $basePath;

    public function __construct($basePath = null)
    {
        $this->basePath = $basePath;

        $this->bootstrapContainer();

        $this->registerContainerAliases();
    }

    public function bootstrapContainer()
    {
        static::setInstance($this);

        $this->instance('app', $this);
        $this->instance('Easth\Server\Application', $this);

        $this->instance('path', $this->path());
    }

    public function version()
    {
        return Server::VERSION;
    }

    public function dispatch($request)
    {
        list($method, $args) = $this->parseIncomingRequest($request);

        try {
            if (isset($this->routes[$method])) {
                return $this->handleFoundRoute([
                    true,
                    $this->routes[$method]['action'],
                    ['args' => $args]
                ]);
            }

            throw new Exception("Not Found {$method}");
        } catch (Exception $e) {
            return $this->sendExceptionToHandler($e);
        } catch (Throwable $e) {
            return $this->sendExceptionToHandler($e);
        }
    }

    public function prepareResponse($response)
    {
        if (!$response instanceof Response) {
            $response = new Response($response, $this->request['i']);
        }

        return $response;
    }

    protected function handleFoundRoute($routeInfo)
    {
        $this->currentRoute = $routeInfo;

        return $this->prepareResponse(
            $this->callActionOnArrayBasedRoute($routeInfo)
        );
    }

    protected function callActionOnArrayBasedRoute($routeInfo)
    {
        $action = $routeInfo[1];

        if (isset($action['uses'])) {
            return $this->prepareResponse($this->callControllerAction($routeInfo));
        }

        foreach ($action as $value) {
            if ($value instanceof Closure) {
                $closure = $value->bindTo($this);
                break;
            }
        }

        try {
            return $this->prepareResponse($this->call($closure, $routeInfo[2]));
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    protected function callControllerAction($routeInfo)
    {
        $uses = $routeInfo[1]['uses'];

        list($controller, $method) = explode('@', $uses);

        if (!method_exists($instance = $this->make($controller), $method)) {
            //throw new NotFoundHttpException;
        }

        return $this->callControllerCallable(
            [$instance, $method], $routeInfo[2]
        );
    }

    protected function callControllerCallable(callable $callable, array $parameters = [])
    {
        try {
            return $this->prepareResponse(
                $this->call($callable, $parameters)
            );
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    protected function resolveExceptionHandler()
    {
        return $this->make('Easth\Server\ExceptionHandler');
    }

    protected function sendExceptionToHandler($e)
    {
        $handler = $this->resolveExceptionHandler();

        if ($e instanceof Error) {
            $e = new FatalThrowableError($e);
        }

        $handler->report($e);

        return $handler->render($this->make('request'), $e);
    }

    public function group(array $attributes, Closure $callback)
    {
        $parentGroupAttributes = $this->groupAttributes;

        $this->groupAttributes = $attributes;

        call_user_func($callback, $this);

        $this->groupAttributes = $parentGroupAttributes;
    }

    protected function mergeNamespaceGroup(array $action)
    {
        if (isset($this->groupAttributes['namespace']) && isset($action['uses'])) {
            $action['uses'] = $this->groupAttributes['namespace'].'\\'.$action['uses'];
        }

        return $action;
    }

    protected function mergeGroupAttributes(array $action)
    {
        return $this->mergeNamespaceGroup($action);
    }

    protected function parseAction($action)
    {
        if (is_string($action)) {
            return ['uses' => $action];
        } elseif (!is_array($action)) {
            return [$action];
        }

        return $action;
    }

    public function addRoute($method, $action)
    {
        $action = $this->parseAction($action);

        if (isset($this->groupAttributes)) {
            $action = $this->mergeGroupAttributes($action);
        }

        $this->routes[$method] = ['method' => $method, 'action' => $action];
    }

    protected function parseIncomingRequest($request)
    {
        $this->instance(Request::class, $request);

        return [$request['m'], $request['p']];
    }

    public function path()
    {
        return $this->basePath.DIRECTORY_SEPARATOR.'app';
    }

    public function basePath($path = null)
    {
        if (isset($this->basePath)) {
            return $this->basePath.($path ? '/'.$path : $path);
        }

        $this->basePath = getcwd();

        return $this->basePath($path);
    }

    public function storagePath($path = null)
    {
        return $this->basePath().'/storage'.($path ? '/'.$path : $path);
    }

    protected function registerContainerAliases()
    {
        $this->aliases = [
            'request' => 'Easth\Server\Request'
        ];
    }
}

