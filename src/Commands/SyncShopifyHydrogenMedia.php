<?php

namespace RefinedDigital\ShopifyHydrogen\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RefinedDigital\CMS\Modules\Media\Events\MediaFileUpdated;
use RefinedDigital\CMS\Modules\Media\Models\Media;
use RefinedDigital\ShopifyHydrogen\Module\Enums\ResourceType;
use RefinedDigital\ShopifyHydrogen\Module\ShopifyAdmin;

class SyncShopifyHydrogenMedia extends Command
{
    protected $signature = 'refinedCMS:sync-shopify-hydrogen-media';

    protected $description = 'Uploads new media to Shopify Files and stores the Shopify file id and cdn url on the media record';

    public function handle(ShopifyAdmin $shopify): int
    {
        if (! $shopify->isConfigured()) {
            $this->warn('Shopify is not configured, set SHOPIFY_DOMAIN and SHOPIFY_ACCESS_TOKEN');

            return self::SUCCESS;
        }

        // ponytail: one file per query, batch the graphql calls if a site uploads hundreds at once
        foreach (Media::whereNull('external_id')->get() as $media) {
            $this->attempt($media, fn () => $this->upload($shopify, $media));
        }

        // Shopify processes a file after fileCreate, its url only exists once that is done
        foreach (Media::whereNotNull('external_id')->whereNull('external_url')->get() as $media) {
            $this->attempt($media, fn () => $this->fetchUrl($shopify, $media));
        }

        return self::SUCCESS;
    }

    /**
     * Runs one media step, logging a failure so one bad file doesn't stop the rest.
     */
    private function attempt(Media $media, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::warning('Shopify media sync failed for media '.$media->id.': '.$e->getMessage());
            $this->error('Media '.$media->id.': '.$e->getMessage());
        }
    }

    /**
     * Sends the local file to a Shopify staged upload target, then creates the Shopify file from it.
     */
    private function upload(ShopifyAdmin $shopify, Media $media): void
    {
        $disk = Storage::disk(config('pages.image.disk'));
        $path = $media->getFileWithDirectory();

        if (! $disk->exists($path)) {
            return;
        }

        $this->line('Uploading '.$path);

        $staged = $shopify->query(<<<'GRAPHQL'
            mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
              stagedUploadsCreate(input: $input) {
                stagedTargets { url resourceUrl parameters { name value } }
                userErrors { field message }
              }
            }
            GRAPHQL, ['input' => [[
            'filename' => $media->file,
            'mimeType' => $media->mime,
            'httpMethod' => 'POST',
            // staged uploads must be FILE, even for images, or fileCreate rejects them
            'resource' => ResourceType::FILE->value,
        ]]])->stagedUploadsCreate;

        $this->throwOnUserErrors($staged->userErrors);
        $target = $staged->stagedTargets[0];

        $request = Http::asMultipart();
        foreach ($target->parameters as $parameter) {
            $request->attach($parameter->name, $parameter->value);
        }
        $request->attach('file', $disk->get($path), $media->file)->post($target->url)->throw();

        $created = $shopify->query(<<<'GRAPHQL'
            mutation fileCreate($files: [FileCreateInput!]!) {
              fileCreate(files: $files) {
                files { id }
                userErrors { field message }
              }
            }
            GRAPHQL, ['files' => [[
            'contentType' => str_starts_with((string) $media->mime, 'image/') ? ResourceType::IMAGE->value : ResourceType::FILE->value,
            'originalSource' => $target->resourceUrl,
        ]]])->fileCreate;

        $this->throwOnUserErrors($created->userErrors);

        $media->external_id = $created->files[0]->id;
        $media->saveQuietly();
    }

    private function fetchUrl(ShopifyAdmin $shopify, Media $media): void
    {
        $node = $shopify->query(<<<'GRAPHQL'
            query file($id: ID!) {
              node(id: $id) {
                ... on GenericFile { url }
                ... on MediaImage { image { url } }
              }
            }
            GRAPHQL, ['id' => $media->external_id])->node;

        $url = $node->url ?? $node->image->url ?? null;
        if (! $url) {
            return;
        }

        $media->external_url = $url;
        $media->saveQuietly();
        MediaFileUpdated::dispatch($media);
        $this->info('Synced '.$media->external_id);
    }

    private function throwOnUserErrors(array $errors): void
    {
        if ($errors) {
            throw new \RuntimeException(collect($errors)->pluck('message')->implode(', '));
        }
    }
}
