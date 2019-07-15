<?php

namespace Shovel\Http\Middleware;

use Closure;
use ArrayObject;
use Commons\When;
use JsonSerializable;
use Illuminate\Http\Response;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse implements \Shovel\HTTP
{
    /**
     * List of classes to ignore.
     *
     * @var array
     */
    private $dontBuild = [
        \Exception::class,
    ];

    /**
     * Handle the response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure  $next
     * @param string[] ...$options
     * @return \Illuminate\Http\Response
     */
    public function handle($request, Closure $next, ...$options)
    {
        $response = $next($request);
        $response = When::isTrue($request->wantsJson(), function () use ($response, $options) {
            $this->beforeResponding($response);
            return $this->buildPayload($response, ...$options);
        }, $response);

        return $response;
    }

    /**
     * Allow transforming of response before it is returned.
     *
     * @param \Illuminate\Http\Response $response
     * @return \Illuminate\Http\Response
     */
    protected function beforeResponding($response)
    {
        return $response;
    }

    /**
     * Construct the response payload.
     *
     * @param \Illuminate\Http\Response $response
     * @param string[] ...$options
     * @return \Illuminate\Http\Response
     */
    private function buildPayload($response, ...$options)
    {
        $metaTag = $options[0] ?? 'meta';
        $dataTag = $options[1] ?? 'data';
        $pageTag = $options[2] ?? 'pagination';

        $payload = $this->getMetaBlock($response, $metaTag);

        if ($response->content()) {
            if ($this->isPaginated($response)) {
                $payload[$metaTag][$pageTag] = $this->getPaginationBlock($response->original);
                $payload[$dataTag] = $response->original->items();
            } elseif ($this->isPaginatedCollection($response)) {
                $payload[$metaTag][$pageTag] = $this->getPaginationBlock($response->original->resource);
                $payload[$dataTag] = $response->original->resource->items();
            } else {
                $payload[$dataTag] = json_decode($response->content());
            }
        }

        $response->setContent($payload);

        return $response;
    }

    /**
     * Returns a string defining whether or not the response is successful.
     *
     * @param int $code
     * @return string
     */
    private function getStatus(int $code)
    {
        $range = substr($code, 0, 1);

        if (in_array($range, [4, 5])) {
            return 'error';
        }

        return 'success';
    }

    /**
     * Returns the text representation of the HTTP status code.
     *
     * @param int $code
     * @return string
     */
    private function getStatusMessage(int $code)
    {
        return self::CODES[$code] ?? 'Unknown';
    }

    /**
     * Returns true if the response is a paginated object.
     *
     * @param \Illuminate\Http\Response $response
     * @return bool
     */
    private function isPaginated($response)
    {
        return $response->original instanceof LengthAwarePaginator;
    }

    /**
     * Returns true if the response is a paginated collection.
     *
     * @param \Illuminate\Http\Response $response
     * @return bool
     */
    private function isPaginatedCollection($response)
    {
        return isset($response->original->resource) &&
               $response->original->resource instanceof LengthAwarePaginator;
    }

    /**
     * Constructs and returns the meta object.
     *
     * @param \Illuminate\Http\Response $response
     * @param string $metaTag
     * @return array
     */
    private function getMetaBlock($response, $metaTag)
    {
        $payload = [
            $metaTag => [
               'code'    => $response->status(),
               'status'  => $this->getStatus($response->status()),
               'message' => $this->getStatusMessage($response->status()),
             ]
         ];

        if (isset($response->additionalMeta)) {
            $payload[$metaTag] = array_merge($payload[$metaTag], $response->additionalMeta);
        }

        return $payload;
    }

    /**
     * Constructs and returns the pagination object.
     *
     * @param \Illuminate\Http\Response $response
     * @return array
     */
    private function getPaginationBlock($paginator)
    {
        return [
            'records'  => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pages'    => $paginator->lastPage(),
            'limit'    => intval($paginator->perPage()),
        ];
    }
}
