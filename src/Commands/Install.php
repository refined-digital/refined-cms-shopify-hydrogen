<?php

namespace RefinedDigital\ShopifyHydrogen\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'refinedCMS:install-shopify-hydrogen';

    protected $description = 'Installs the shopify hydrogen module';

    public function handle(): int
    {
        $env = [
            'SHOPIFY_DOMAIN' => $this->ask('Shopify domain (myshop.myshopify.com)'),
            'SHOPIFY_ACCESS_TOKEN' => $this->secret('Admin api access token (needs the write_files and read_files scopes)'),
            'PUBLIC_URL' => $this->ask('Public (Hydrogen) url', 'https://www.example.com'),
        ];

        $file = file_get_contents(app()->environmentFilePath());
        foreach ($env as $key => $value) {
            $line = $key.'='.$value;
            $file = preg_match("/^{$key}=.*$/m", $file)
                ? preg_replace("/^{$key}=.*$/m", $line, $file)
                : rtrim($file)."\n".$line."\n";
        }
        file_put_contents(app()->environmentFilePath(), $file);

        $this->call('vendor:publish', ['--tag' => 'shopify-hydrogen']);

        $this->info('Shopify Hydrogen has been successfully installed');
        $this->line('Run the scheduler (php artisan schedule:work, or cron in production) so new media is pushed to Shopify');

        return self::SUCCESS;
    }
}
