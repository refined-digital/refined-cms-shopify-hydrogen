<?php

use \RefinedDigital\SocialMedia\Module\Http\Repositories\SocialMediaRepository;

if (! function_exists('socialMedia')) {
    function socialMedia()
    {
        return app(SocialMediaRepository::class);
    }
}
