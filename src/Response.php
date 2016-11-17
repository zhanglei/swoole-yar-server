<?php
namespace Easth\Server;

use Exception;

class Response
{
    const ERR_OKEY = 0x0;

    const ERR_EXCEPTION = 0x40;

    protected $content;

    protected $request;

    protected $statusCode;

    protected $response = [
        'i' => 0,
        's' => self::ERR_OKEY,
        'r' => '',
        'o' => '',
        'e' => '',
    ];

    public function __construct($content = '', $id, $status = self::ERR_OKEY)
    {
        $this->setId($id);
        $this->setContent($content);
        $this->setStatusCode($status);
    }

    public function setId($id)
    {
        $this->response['i'] = $id;

        return $this;
    }

    public function setContent($content)
    {
        $this->response['r'] = $content;

        return $this;
    }

    public function withException(Exception $e)
    {
        $this->response['e'] = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        return $this;
    }

    public function send()
    {
        $response = msgpack_pack($this->response);
        $responseLen = strlen($response);

        $result = pack('NnNNa32a32Na8a'.$responseLen,
                       $this->response['i'],
                       0,
                       Header::MAGIC_NUM,
                       0,
                       'Swoole Server',
                       '',
                       8 + $responseLen,
                       'MSGPACK',
                       $response
        );

        return $result;
    }

    public function setStatusCode($statusCode)
    {
        $this->response['s'] = $statusCode;
        return $this;
    }
}