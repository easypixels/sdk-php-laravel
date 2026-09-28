<?php

namespace EasyPixel\Laravel\Tests\Fixtures;

use EasyPixel\HttpClient;

/**
 * HttpClient that answers from a queue of prepared responses and records what
 * it was asked to send, the same seam the SDK's own MockHttpClient uses.
 *
 * With an empty queue it answers 202 Accepted, so a test only prepares a
 * response when the response itself is what it is about.
 */
class RecordingHttpClient extends HttpClient
{
    /** @var array[] */
    private $responses = [];

    /** @var array[] */
    private $requests = [];

    /**
     * @param int    $status
     * @param array  $body
     * @param array  $headers Keys lowercased, as the SDK expects
     * @return $this
     */
    public function addResponse($status, array $body = [], array $headers = [])
    {
        $this->responses[] = [
            'body'    => json_encode($body),
            'status'  => $status,
            'error'   => null,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * @return array[] Each entry: ['url', 'method', 'headers', 'body']
     */
    public function getRequests()
    {
        return $this->requests;
    }

    /**
     * @return array|null
     */
    public function getLastRequest()
    {
        return empty($this->requests) ? null : end($this->requests);
    }

    /**
     * Decoded body of the last request.
     *
     * @return array
     */
    public function getLastBody()
    {
        $request = $this->getLastRequest();

        if ($request === null || $request['body'] === null) {
            return [];
        }

        return json_decode($request['body'], true);
    }

    /** @return string */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /** @return int */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /** @return int */
    public function getConnectTimeout()
    {
        return $this->connectTimeout;
    }

    /**
     * {@inheritdoc}
     */
    protected function executeCurl($url, array $curlOptions)
    {
        $this->requests[] = [
            'url'     => $url,
            'method'  => isset($curlOptions[CURLOPT_CUSTOMREQUEST]) ? $curlOptions[CURLOPT_CUSTOMREQUEST] : 'GET',
            'headers' => isset($curlOptions[CURLOPT_HTTPHEADER]) ? $curlOptions[CURLOPT_HTTPHEADER] : [],
            'body'    => isset($curlOptions[CURLOPT_POSTFIELDS]) ? $curlOptions[CURLOPT_POSTFIELDS] : null,
        ];

        if (empty($this->responses)) {
            return [
                'body' => json_encode([
                    'message'           => 'Webhook accepted.',
                    'scene_id'          => 42,
                    'matrix_id'         => 7,
                    'variables_updated' => [],
                ]),
                'status'  => 202,
                'error'   => null,
                'headers' => [],
            ];
        }

        return array_shift($this->responses);
    }
}
