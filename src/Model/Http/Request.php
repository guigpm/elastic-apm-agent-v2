<?php

namespace Elastic\Apm\PhpAgent\Model\Http;

use Elastic\Apm\PhpAgent\Util\BaseObject;

class Request extends BaseObject
{
    /**
     * @var string
     */
    private $url;

    /**
     * @var string
     */
    private $method;

    public function __construct(?array $config = [])
    {
        if (isset($config['url'])) {
            $this->setUrl($config['url']);
        }
        if (isset($config['method'])) {
            $this->setMethod($config['method']);
        }
        unset($config['url'], $config['method']);
        parent::__construct($config);
    }

    /**
     * @param string $url
     */
    public function setUrl(string $url): void
    {
        $urlParts = parse_url($url);
        $this->url = new Url([
            'raw' => $url,
            'protocol' => $urlParts['scheme'] ?? 'http',
            'full' => $url,
            'hostname' => $urlParts['host'] ?? '',
            'port' => $urlParts['port'] ?? (($urlParts['scheme'] ?? '') === 'https' ? 443 : 80),
            'pathname' => $urlParts['path'] ?? '',
            'search' => $urlParts['query'] ?? '',
            'hash' => $urlParts['fragment'] ?? ''
        ]);
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @param string $method
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }



    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'method' => $this->method
        ];
    }

    /**
     * Define object validation rules
     *
     * @return array
     */
    public function validationRules(): array
    {
        return [
            'required' => ['url', 'method']
        ];
    }
}
