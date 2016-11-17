<?php
namespace Easth\Server;

use Exception;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Debug\ExceptionHandler as SymfonyExceptionHandler;

class ExceptionHandler
{
    protected $dontReport = [];

    public function report(Exception $e)
    {
        if ($this->shouldntReport($e)) {
            return;
        }

        // @TODO
    }

    protected function shouldntReport(Exception $e)
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    public function render($request, Exception $e)
    {

        $response = new Response('', $request['i'], Response::ERR_EXCEPTION);
        $response->withException($e);

        return $response;
    }

    public function renderForConsole($output, Exception $e)
    {
        (new ConsoleApplication)->renderException($e, $output);
    }
}