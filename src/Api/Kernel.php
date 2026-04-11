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

        // Plugin endpoint — consumed by Roger's CDM plugin and any other
        // client written against ECFMP/flow's PluginApiController. Response
        // shape is kept intentionally identical to upstream ECFMP/flow so
        // CDMConfig.xml's <FlowRestrictions url="..."/> can be pointed here
        // and the plugin will work without any code changes.
        //
        // NB: CDM only retains measures whose type is minimum_departure_interval
        // or per_hour, and parses starttime/endtime via fixed substrings assuming
        // the format "YYYY-MM-DDTHH:mm:ssZ" (length 20, no microseconds).
        $app->get('/api/v1/plugin', function ($req, $res) {
            $deleted = (($req->getQueryParams()['deleted'] ?? '0') === '1');

            $query = FlowMeasure::with('fir')->orderBy('start_time');
            if ($deleted) {
                $query->withTrashed();
            }

            $measures = $query->get()->map(fn (FlowMeasure $fm) => self::serializeMeasureForPlugin($fm))->all();

            $firs = Fir::orderBy('identifier')->get()->map(fn (Fir $f) => [
                'id'         => $f->id,
                'identifier' => $f->identifier,
                'name'       => $f->name,
            ])->all();

            return self::json($res, [
                'events'                     => [],
                'flight_information_regions' => $firs,
                'flow_measures'              => $measures,
            ]);
        });

        return $app;
    }

    /**
     * Format a datetime the way CDM's substring parser expects:
     * YYYY-MM-DDTHH:mm:ssZ  (length 20, UTC, no microseconds).
     */
    private static function formatIsoUtc(\DateTimeInterface $dt): string
    {
        return (new \DateTimeImmutable('@' . $dt->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Transform a FlowMeasure into the shape ECFMP/flow's PluginApiController
     * returns (which CDM is hardcoded to parse). Filters are stored in the
     * database already in the array-of-{type,value} shape CDM expects, so
     * they pass through unchanged.
     */
    private static function serializeMeasureForPlugin(FlowMeasure $fm): array
    {
        $filters = is_array($fm->filters) ? $fm->filters : [];

        return [
            'id'         => (int) $fm->id,
            'ident'      => (string) $fm->identifier,
            'event_id'   => null,
            'reason'     => (string) $fm->reason,
            'starttime'  => self::formatIsoUtc($fm->start_time),
            'endtime'    => self::formatIsoUtc($fm->end_time),
            'measure'    => [
                'type'  => (string) $fm->type,
                'value' => $fm->value,
            ],
            'filters'    => $filters,
            'notified_flight_information_regions' => $fm->fir_id !== null ? [(int) $fm->fir_id] : [],
            'withdrawn_at' => $fm->deleted_at ? self::formatIsoUtc($fm->deleted_at) : null,
        ];
    }

    private static function json(ResponseInterface $res, mixed $payload): ResponseInterface
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
