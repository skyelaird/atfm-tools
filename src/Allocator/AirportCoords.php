<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * Fallback coordinate lookup for airports that aren't in our configured
 * `airports` table but appear as ADEP/ADES for flights we care about.
 *
 * Used by the ETA estimator when a flight's origin or destination is an
 * ICAO code we don't manage. Keeping it as a static table avoids
 * maintaining a full AIRAC database on the WHC server.
 *
 * Extend as the test matrix grows. If a pilot files from an airport not
 * in this list, the allocator logs "unmeasurable ADEP" and skips the
 * flight this cycle — it'll come into scope on the next cycle once the
 * flight becomes airborne and we switch to observed-position ETA.
 *
 * @var array<string, array{0:float,1:float}>
 */
final class AirportCoords
{
    private const COORDS = [
        // Our 7 Canadian airports
        'CYHZ' => [44.8808, -63.5086],
        'CYOW' => [45.3225, -75.6692],
        'CYUL' => [45.4706, -73.7408],
        'CYVR' => [49.1939, -123.1844],
        'CYWG' => [49.9100, -97.2399],
        'CYYC' => [51.1139, -114.0203],
        'CYYZ' => [43.6772, -79.6306],

        // Common Canadian ADEPs
        'CYQB' => [46.7911, -71.3933],  // Quebec
        'CYQM' => [46.1122, -64.6786],  // Moncton
        'CYQX' => [48.9369, -54.5681],  // Gander
        'CYYT' => [47.6189, -52.7519],  // St John's
        'CYYG' => [46.2900, -63.1211],  // Charlottetown
        'CYSJ' => [45.3158, -65.8903],  // Saint John NB
        'CYQY' => [46.1614, -60.0478],  // Sydney NS
        'CYFC' => [45.8689, -66.5372],  // Fredericton
        'CYHM' => [43.1736, -79.9350],  // Hamilton
        'CYKZ' => [43.8625, -79.3706],  // Toronto Buttonville
        'CYTZ' => [43.6275, -79.3961],  // Toronto Billy Bishop
        'CYKF' => [43.4608, -80.3786],  // Kitchener/Waterloo
        'CYQT' => [48.3719, -89.3239],  // Thunder Bay
        'CYPG' => [49.9025, -98.2739],  // Portage la Prairie
        'CYXE' => [52.1708, -106.6997], // Saskatoon
        'CYQR' => [50.4319, -104.6659], // Regina
        'CYEG' => [53.3097, -113.5800], // Edmonton Intl
        'CYXS' => [53.8894, -122.6789], // Prince George
        'CYXX' => [49.0253, -122.3614], // Abbotsford
        'CYYJ' => [48.6469, -123.4258], // Victoria
        'CYXY' => [60.7095, -135.0674], // Whitehorse
        'CYFB' => [63.7564, -68.5558],  // Iqaluit
        'CYZF' => [62.4628, -114.4403], // Yellowknife

        // Common North American ADEPs
        'KJFK' => [40.6398, -73.7789],
        'KLGA' => [40.7769, -73.8740],
        'KEWR' => [40.6925, -74.1687],
        'KBOS' => [42.3656, -71.0096],
        'KDCA' => [38.8521, -77.0377],
        'KIAD' => [38.9531, -77.4565],
        'KBWI' => [39.1754, -76.6683],
        'KPHL' => [39.8729, -75.2437],
        'KORD' => [41.9786, -87.9048],
        'KMDW' => [41.7868, -87.7522],
        'KDTW' => [42.2124, -83.3534],
        'KCLE' => [41.4117, -81.8498],
        'KATL' => [33.6407, -84.4277],
        'KMIA' => [25.7933, -80.2906],
        'KMCO' => [28.4294, -81.3089],
        'KLAX' => [33.9425, -118.4081],
        'KSFO' => [37.6213, -122.3790],
        'KSEA' => [47.4502, -122.3088],
        'KDEN' => [39.8561, -104.6737],
        'KDFW' => [32.8998, -97.0403],
        'KIAH' => [29.9844, -95.3414],
        'KSAN' => [32.7338, -117.1933],
        'KPDX' => [45.5898, -122.5950],
        'KMSP' => [44.8820, -93.2218],
        'KBUF' => [42.9405, -78.7322],

        // Caribbean / Mexico
        'MMUN' => [21.0365, -86.8770],  // Cancun
        'MDPC' => [18.5674, -68.3634],  // Punta Cana
        'MKJP' => [17.9356, -76.7875],  // Kingston
        'MYNN' => [25.0390, -77.4662],  // Nassau
        'TBPB' => [13.0746, -59.4925],  // Barbados

        // US — additional
        'KFLL' => [26.0726, -80.1527],  // Fort Lauderdale
        'KLAS' => [36.0840, -115.1537], // Las Vegas
        'KSLC' => [40.7884, -111.9778], // Salt Lake City
        'KPHX' => [33.4373, -112.0078], // Phoenix
        'KMEM' => [35.0424, -89.9767],  // Memphis
        'PANC' => [61.1741, -149.9962], // Anchorage
        'PAJN' => [58.3550, -134.5763], // Juneau

        // European ADEPs
        'EGLL' => [51.4700, -0.4543],
        'EGKK' => [51.1537, -0.1821],
        'EGCC' => [53.3537, -2.2750],
        'EIDW' => [53.4213, -6.2701],
        'LFPG' => [49.0097, 2.5479],
        'EHAM' => [52.3086, 4.7639],
        'EDDF' => [50.0379, 8.5622],
        'EDDM' => [48.3537, 11.7750],  // Munich
        'LQSA' => [43.8246, 18.3315],  // Sarajevo

        // Asia-Pacific ADEPs
        'RJTT' => [35.5533, 139.7811],  // Tokyo Haneda
        'RJAA' => [35.7647, 140.3864],  // Tokyo Narita
        'VHHH' => [22.3080, 113.9185],  // Hong Kong
        'YSSY' => [-33.9461, 151.1772], // Sydney
        'YMML' => [-37.6733, 144.8433], // Melbourne
        'WSSS' => [1.3502, 103.9944],   // Singapore
        'RKSI' => [37.4691, 126.4505],  // Seoul Incheon
        'ZBAA' => [40.0799, 116.6031],  // Beijing
        'ZSPD' => [31.1434, 121.8052],  // Shanghai Pudong
        'VIDP' => [28.5665, 77.1031],   // Delhi
        'OMDB' => [25.2528, 55.3644],   // Dubai
        'OTBD' => [25.2731, 51.6081],   // Doha
    ];

    /** @return array{0:float,1:float}|null [latitude, longitude] or null */
    public static function coords(string $icao): ?array
    {
        $icao = strtoupper(trim($icao));
        return self::COORDS[$icao] ?? null;
    }

    public static function known(string $icao): bool
    {
        return isset(self::COORDS[strtoupper(trim($icao))]);
    }
}
