<?php

namespace RefinedDigital\ShopifyHydrogen\Module;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ShopifyAdmin
{
    /**
     * Runs a query against the Shopify Admin GraphQL api.
     *
     * @throws RequestException on an http error, or when Shopify returns top level graphql errors
     */
    public function query(string $query, array $variables = []): object
    {
        $domain = preg_replace('#^https?://#', '', rtrim((string) config('shopify-hydrogen.domain'), '/'));
        $url = 'https://'.$domain.'/admin/api/'.config('shopify-hydrogen.api_version').'/graphql.json';

        $response = Http::withHeaders(['X-Shopify-Access-Token' => config('shopify-hydrogen.token')])
            ->acceptJson()
            ->post($url, array_filter(['query' => $query, 'variables' => $variables]))
            ->throw();

        $body = $response->object();

        if (! empty($body->errors)) {
            throw new RequestException($response);
        }

        return $body->data;
    }

    public function isConfigured(): bool
    {
        return config('shopify-hydrogen.token') && config('shopify-hydrogen.domain');
    }
}
