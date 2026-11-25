<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Keys;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @internal
 */
final readonly class FormGuard
{
    public function __construct(
        private string $secret,
    ) {
    }

    public function token(string $action, string $subject = ''): string
    {
        return hash_hmac('sha256', 'tollbooth-dashboard|'.$action.'|'.$subject, $this->secret);
    }

    public function check(Request $request, string $action, string $subject = ''): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !hash_equals($this->token($action, $subject), $token)) {
            throw new AccessDeniedHttpException('The form token is not valid.');
        }

        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && !\in_array($site, ['same-origin', 'none'], true)) {
            throw new AccessDeniedHttpException('The form was not sent from the dashboard.');
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && 'null' !== $origin && rtrim($origin, '/') !== $request->getSchemeAndHttpHost()) {
            throw new AccessDeniedHttpException('The form was not sent from the dashboard.');
        }
    }
}
