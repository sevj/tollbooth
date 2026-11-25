<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[AsController]
final readonly class PagesController
{
    public function __construct(
        private Security $security,
        private AuthenticationUtils $authentication,
    ) {
    }

    public function home(): Response
    {
        $user = $this->security->getUser();
        $links = [
            ['/_tollbooth/docs/shop', 'API documentation', 'public'],
            ['/_tollbooth/health.json', 'Health report', 'admin'],
            ['/_tollbooth/health/ready', 'Readiness probe', 'public'],
            ['/api/v2/products', 'Shop catalog (JSON:API)', 'public'],
            ['/api/admin/stats', 'Admin stats (session or Basic)', 'admin'],
        ];

        $items = implode('', array_map(static fn (array $link): string => \sprintf(
            '<li><a href="%s">%s</a><span>%s</span></li>',
            htmlspecialchars($link[0], \ENT_QUOTES),
            htmlspecialchars($link[1], \ENT_QUOTES),
            $link[2],
        ), $links));

        $account = null === $user
            ? '<a class="button" href="/login">Log in</a>'
            : \sprintf('<span>Signed in as <b>%s</b></span> <a class="button" href="/logout">Log out</a>', htmlspecialchars($user->getUserIdentifier(), \ENT_QUOTES));

        return $this->page('Tollbooth demo', \sprintf('<header><h1>Shop API demo</h1><div>%s</div></header><ul class="links">%s</ul>', $account, $items));
    }

    public function login(): Response
    {
        $error = $this->authentication->getLastAuthenticationError();
        $message = null === $error ? '' : '<p class="error">Wrong username or password.</p>';

        return $this->page('Log in · Tollbooth demo', \sprintf(<<<'HTML'
            <form method="post" action="/login" class="login">
            <h1>Log in</h1>
            %s
            <label>Username <input name="_username" value="%s" autocomplete="username" required autofocus></label>
            <label>Password <input name="_password" type="password" autocomplete="current-password" required></label>
            <button type="submit">Log in</button>
            <p class="hint">admin / admin, or ada / ada</p>
            </form>
            HTML, $message, htmlspecialchars($this->authentication->getLastUsername(), \ENT_QUOTES)));
    }

    private function page(string $title, string $body): Response
    {
        return new Response(\sprintf(<<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>%s</title>
            <link rel="icon" href="data:,">
            <style>
            body{margin:0;min-height:100vh;background:#0d0e10;color:#edeae3;font:15px/1.5 Inter,system-ui,sans-serif;display:flex;justify-content:center;align-items:flex-start;padding:64px 16px;box-sizing:border-box}
            main{width:100%%;max-width:560px}
            header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}
            h1{margin:0;font-size:24px;font-weight:600}
            a{color:#f0b429;text-decoration:none}
            .button,button{display:inline-block;background:#f0b429;color:#0d0e10;border:0;padding:8px 14px;font:600 14px Inter,system-ui,sans-serif;cursor:pointer}
            .links{list-style:none;margin:0;padding:0;border:1px solid #24282d}
            .links li{display:flex;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid #1f2226;font-family:ui-monospace,monospace;font-size:13px}
            .links li:last-child{border-bottom:0}
            .links span{color:#9c988f}
            .login{display:flex;flex-direction:column;gap:14px;background:#131518;border:1px solid #24282d;padding:28px}
            label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#9c988f}
            input{background:#0d0e10;border:1px solid #2c3036;color:#edeae3;padding:10px;font:14px ui-monospace,monospace}
            .error{color:#ff8a7a;margin:0}
            .hint{color:#8a867d;font:12px ui-monospace,monospace;margin:0}
            </style>
            </head>
            <body><main>%s</main></body>
            </html>
            HTML, htmlspecialchars($title, \ENT_QUOTES), $body));
    }
}
