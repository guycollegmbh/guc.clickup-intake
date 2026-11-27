<?php

it('marks every response as noindex', function (string $uri) {
    $this->get($uri)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
})->with(['/login', '/up', '/does-not-exist']);
