<?php

/*
 * This file is part of the CsaGuzzleBundle package
 *
 * (c) Charles Sarrazin <charles@sarraz.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code
 */

namespace Csa\Bundle\GuzzleBundle\GuzzleHttp\Cache;

use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class MockStorageAdapter implements StorageAdapterInterface
{
    /**
     * @var string
     */
    private $storagePath;

    /**
     * @var array
     */
    private $headersBlacklist;

    /**
     * @param $storagePath
     * @param null|array $headersBlacklist
     */
    public function __construct($storagePath, $headersBlacklist = null)
    {
        $this->storagePath = $storagePath;

        if (is_array($headersBlacklist)) {
            $this->headersBlacklist = $headersBlacklist;
        } else {
            $this->headersBlacklist = [
                'User-Agent',
                'Host',
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(RequestInterface $request)
    {
        $path = $this->getPath($request);

        if (!file_exists($path)) {
            // Try to find file without host (for BC)
            $path = $this->getPath($request, false);

            if (!file_exists($path)) {
                throw new \RuntimeException('Record not found.');
            }
        }

        return Psr7\parse_response(file_get_contents($path));
    }

    /**
     * {@inheritdoc}
     */
    public function save(RequestInterface $request, ResponseInterface $response)
    {
        file_put_contents($this->getPath($request), Psr7\str($response));

        $response->getBody()->seek(0);
    }

    /**
     * Create a fingerprint for each request.
     *
     * As it is for mocking (and not for real caching), ignore some
     * characteristics like the 'User-Agent' to avoid stale cache
     * when updating PHP or Guzzle.
     *
     * @param RequestInterface $request
     * @param bool             $withHost
     *
     * @return string The path to the mock file
     */
    public function getPath(RequestInterface $request, $withHost = true)
    {
        $headers = $request->getHeaders();
        foreach ($headers as $name => $values) {
            if (in_array($name, $this->headersBlacklist)) {
                unset($headers[$name]);
            }
        }

        $fingerprint = substr(md5(serialize([
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'user_info' => $request->getUri()->getUserInfo(),
            'port' => $request->getUri()->getPort(),
            'scheme' => $request->getUri()->getScheme(),
            'headers' => $headers,
        ])), 0, 7);

        if (true === $withHost) {
            $path = sprintf(
                '%s_%s_%s____%s',
                str_pad($request->getMethod(), 6, '_'),
                $request->getUri()->getHost(),
                urldecode(ltrim($request->getUri()->getPath(), '/').'-'.$request->getUri()->getQuery()),
                $fingerprint
            );
        } else {
            $path = sprintf(
                '%s_%s____%s',
                str_pad($request->getMethod(), 6, '_'),
                urldecode(ltrim($request->getUri()->getPath(), '/').'-'.$request->getUri()->getQuery()),
                $fingerprint
            );
        }

        $path = preg_replace('/[^a-zA-Z0-9_+@\-\.]/', '-', $path);
        $path = str_split($path, 128);
        $filename = array_pop($path);

        $dir = $this->storagePath.'/'.implode('/', $path);

        if(!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.$filename.'.txt';
    }
}
