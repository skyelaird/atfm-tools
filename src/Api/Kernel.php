<?php

declare(strict_types=1);

namespace Atfm\Api;

use Atfm\Models\Fir;
use Atfm\Models\FlowMeasure;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the Slim app and registers all API routes.
 *
 * Deliberately tiny — every route is inline so you can read the whole
 * surface in one screen. Move things into controllers only when they
 * outgrow this file.
 */
final class Kernel
{
    public static function create(): App
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(
            displayErrorDetails: ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            logErrors: true,
            logErrorDetails: true,
        );

        // Health
        $app->get('/api/health', function (ServerRequestInterface $req, ResponseInterface $res) {
            return self::json($res, ['status' => 'ok', 'time' => gmdate('c')]);
        });

        // FIRs
        $app->get('/api/v1/flight-information-region', function ($req, $res) {
            return self::json($res, Fir::orderBy('identifier')->get()->toArray());
        });
        $app->get('/api/v1/flight-information-region/{id}', function ($req, $res, array $args) {
            $fir = Fir::find((int) $args['id']);
            return $fir
                ? self::json($res, $fir->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
        });

        // Flow measures
        $app->get('/api/v1/flow-measure', function (ServerRequestInterface $req, ResponseInterface $res) {
            $params = $req->getQueryParams();
            $query  = FlowMeasure::with('fir')->orderBy('start_time');

            if (($params['state'] ?? null) === 'active') {
                $now = new DateTimeImmutable('now');
                $query->where('start_time', '<=', $now)
                      ->where('end_time',   '>=', $now);
            }

            return self::json($res, $query->get()->toArray());
        });
        $app->get('/api/v1/flow-measure/{id}', function ($req, $res, array $args) {
            $fm = FlowMeasure::with('fir')->find((int) $args['id']);
            return $fm
                ? self::json($res, $fm->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
        });

        return $app;
    }

    private static function json(ResponseInterface $res, mixed $payload): ResponseInterface
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
