<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Tollbooth\Core\Metadata\EndpointMetadata;
use Tollbooth\Core\Metadata\EndpointRegistry;
use Tollbooth\Demo\DemoKeys;
use Tollbooth\Demo\Traffic\TrafficProfile;

#[AsCommand('demo:traffic', 'Send live calls to the demo APIs, as their consumers would')]
final readonly class TrafficCommand
{
    public function __construct(
        private KernelInterface $kernel,
        private EndpointRegistry $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Calls per second')] float $rate = 4.0,
        #[Option('Seconds to run, 0 for ever')] int $duration = 0,
    ): int {
        $endpoints = [];
        foreach ($this->registry->all() as $endpoint) {
            $endpoints[substr($endpoint->routeName(), \strlen('tollbooth_'))] = $endpoint;
        }

        $io->writeln(\sprintf('Sending about %.1f calls per second%s. Ctrl+C to stop.', $rate, $duration > 0 ? ' for '.$duration.' s' : ''));
        $end = $duration > 0 ? time() + $duration : \PHP_INT_MAX;
        $sent = 0;
        $statuses = [];
        while (time() < $end) {
            $consumer = TrafficProfile::consumer();
            $call = TrafficProfile::call($consumer);
            $endpoint = $endpoints[$call] ?? null;
            if (null !== $endpoint) {
                $status = $this->send($consumer, $call, $endpoint);
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                ++$sent;
                if (0 === $sent % 50) {
                    ksort($statuses);
                    $io->writeln(\sprintf('%d calls · %s', $sent, implode(' · ', array_map(static fn (int $code, int $count): string => $code.'×'.$count, array_keys($statuses), $statuses))));
                }
            }
            usleep((int) (1_000_000 / max(0.1, $rate) * (0.5 + mt_rand() / mt_getrandmax())));
        }

        return Command::SUCCESS;
    }

    private function send(string $consumer, string $call, EndpointMetadata $endpoint): int
    {
        $body = TrafficProfile::body($call, 0 !== mt_rand(0, 25));
        $ip = 'anonymous' === $consumer ? '198.51.100.'.mt_rand(2, 250) : '203.0.113.'.(crc32($consumer) % 200 + 10);
        $server = ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => $consumer.'/1.0'];
        $auth = TrafficProfile::CONSUMERS[$consumer]['auth'];
        if ('key' === $auth) {
            $server['HTTP_X_API_KEY'] = DemoKeys::key($consumer);
        } elseif ('user' === $auth) {
            $server['PHP_AUTH_USER'] = $consumer;
            $server['PHP_AUTH_PW'] = $consumer;
        } elseif ('jwt' === $auth) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.TrafficProfile::TOKENS[$consumer];
        }

        $request = Request::create(
            '/api'.TrafficProfile::path($endpoint->path, $call),
            $endpoint->method,
            server: $server,
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $response = $this->kernel->handle($request);
        if ($this->kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        return $response->getStatusCode();
    }
}
